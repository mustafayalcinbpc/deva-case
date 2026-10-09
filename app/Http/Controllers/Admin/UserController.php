<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CleaningStatus;
use App\Enums\StepStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Catalog\ResetUserPasswordRequest;
use App\Http\Requests\Admin\Catalog\StoreUserRequest;
use App\Http\Requests\Admin\Catalog\UpdateUserRequest;
use App\Models\Cleaning;
use App\Models\User;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Kullanıcılar ve rolleri (R-40–R-43). Kayıtlar kullanıcıya bağlı olduğu için kullanıcı silinmez,
 * pasife alınır; pasif kullanıcının açık oturumu bir sonraki istekte kapanır (EnsureUserIsActive).
 *
 * R-36, K-08: işten ayrılan kişinin açık kayıtları kendiliğinden kapanmaz ve sorumluluk
 * devredilemez (R-15). Pasife alırken bu kayıtlar gösterilir; yönetici gerekirse "personel
 * ayrıldı" gerekçesiyle iptal eder. Pasife almak yine de engellenmez.
 */
class UserController extends Controller
{
    private const PER_PAGE = 50;

    public function index(): View
    {
        $users = User::query()
            ->select('users.*')
            ->addSelect([
                'open_owned_count' => $this->openCleanings()
                    ->selectRaw('count(*)')
                    ->whereColumn('cleanings.owner_id', 'users.id'),
                'open_assigned_count' => $this->openCleanings()
                    ->selectRaw('count(*)')
                    ->whereColumn('cleanings.owner_id', '!=', 'users.id')
                    ->whereHas('steps', $this->unfinishedStepAssignedTo(fn (Builder $query) => $query->whereColumn('cleaning_step_assignees.user_id', 'users.id'))),
            ])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->paginate(self::PER_PAGE);

        return view('admin.users.index', ['users' => $users]);
    }

    public function create(): View
    {
        return view('admin.users.create', ['roles' => UserRole::cases()]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $user = User::create([...$request->validated(), 'is_active' => true]);

        return redirect()
            ->route('admin.users.index')
            ->with('status', "Kullanıcı eklendi: {$user->name}");
    }

    public function edit(Request $request, User $user): View
    {
        return view('admin.users.edit', [
            'user' => $user,
            'roles' => UserRole::cases(),
            'isSelf' => $user->is($request->user()),
            ...$this->openRecords($user),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        // Yönetici kendini yönetimin dışında bırakamaz; böylece en az bir aktif yönetici kalır.
        if ($user->is($request->user()) && $request->role() !== UserRole::Manager) {
            throw ValidationException::withMessages([
                'role' => 'Kendi yönetici rolünüzü kaldıramazsınız.',
            ]);
        }

        $user->update($request->validated());

        return redirect()
            ->route('admin.users.index')
            ->with('status', "Kullanıcı güncellendi: {$user->name}");
    }

    public function password(ResetUserPasswordRequest $request, User $user): RedirectResponse
    {
        // "Beni hatırla" çerezleri de geçersiz olur.
        $user->forceFill([
            'password' => $request->validated('password'),
            'remember_token' => Str::random(60),
        ])->save();

        return redirect()
            ->route('admin.users.edit', $user)
            ->with('status', "{$user->name} için yeni şifre kaydedildi.");
    }

    public function deactivate(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            throw ValidationException::withMessages([
                'is_active' => 'Kendi hesabınızı pasife alamazsınız.',
            ]);
        }

        $user->update(['is_active' => false]);

        $open = $this->openRecords($user);
        $message = "{$user->name} pasife alındı; açık oturumu bir sonraki işleminde kapanır.";

        if ($open['ownedRecords']->isNotEmpty() || $open['assignedRecords']->isNotEmpty()) {
            $message .= ' Açık kayıtları aşağıda listelenmiştir.';
        }

        return redirect()
            ->route('admin.users.edit', $user)
            ->with('status', $message);
    }

    public function activate(User $user): RedirectResponse
    {
        $user->update(['is_active' => true]);

        return redirect()
            ->route('admin.users.edit', $user)
            ->with('status', "{$user->name} yeniden aktif.");
    }

    /**
     * Kişinin sahibi olduğu açık kayıtlar ve başkasına ait açık kayıtlarda bitmemiş bir adımda
     * görevli olduğu kayıtlar.
     *
     * @return array{ownedRecords: Collection<int, Cleaning>, assignedRecords: Collection<int, Cleaning>}
     */
    private function openRecords(User $user): array
    {
        $with = ['facility:id,code', 'line:id,code', 'machine:id,code,name', 'owner:id,name'];

        return [
            'ownedRecords' => $this->openCleanings()
                ->where('owner_id', $user->id)
                ->with($with)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(),
            'assignedRecords' => $this->openCleanings()
                ->where('owner_id', '!=', $user->id)
                ->whereHas('steps', $this->unfinishedStepAssignedTo(fn (Builder $query) => $query->where('user_id', $user->id)))
                ->with($with)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(),
        ];
    }

    /**
     * @return Builder<Cleaning>
     */
    private function openCleanings(): Builder
    {
        return Cleaning::query()->whereIn('cleanings.status', [CleaningStatus::Created, CleaningStatus::InProgress]);
    }

    /**
     * Bitmemiş (bekleyen, çalışan ya da duraklatılmış) ve kişinin aktif görevli olduğu adım.
     *
     * @param  Closure(Builder): mixed  $matchesUser
     */
    private function unfinishedStepAssignedTo(Closure $matchesUser): Closure
    {
        return fn (Builder $steps) => $steps
            ->whereIn('cleaning_steps.status', [StepStatus::Pending, StepStatus::Running, StepStatus::Paused])
            ->whereHas('activeAssignees', $matchesUser);
    }
}

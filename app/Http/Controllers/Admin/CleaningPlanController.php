<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CleaningPlanKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Planning\SaveCleaningPlanRequest;
use App\Models\CleaningPlan;
use App\Models\Machine;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Validation\ValidationException;

/**
 * Temizlik planları (K-20): makine bazında periyodik kural ya da "üretim iş emri tamamlanınca"
 * tetiği. Plan yapılması gereken temizliği görev olarak üretir (CleaningTaskGenerator). Plan
 * silinmez, kullanımdan kaldırılır; görev üretmiş planın makinesi değişmez. Kullanımdan kaldırılan
 * planın açık görevi olduğu gibi kalır.
 */
class CleaningPlanController extends Controller
{
    private const PER_PAGE = 50;

    public function index(): View
    {
        return view('admin.cleaning-plans.index', [
            'plans' => CleaningPlan::query()
                ->join('machines', 'machines.id', '=', 'cleaning_plans.machine_id')
                ->join('lines', 'lines.id', '=', 'machines.line_id')
                ->join('facilities', 'facilities.id', '=', 'lines.facility_id')
                ->select('cleaning_plans.*')
                ->with(['machine.line.facility', 'activeTask'])
                ->orderByDesc('cleaning_plans.is_active')
                ->orderBy('facilities.code')
                ->orderBy('lines.code')
                ->orderBy('machines.code')
                ->orderBy('cleaning_plans.kind')
                ->paginate(self::PER_PAGE),
        ]);
    }

    public function create(): View
    {
        return $this->form(new CleaningPlan(['kind' => CleaningPlanKind::Periodic, 'interval_days' => 7]), 0);
    }

    public function store(SaveCleaningPlanRequest $request): RedirectResponse
    {
        $plan = CleaningPlan::create($request->attributesToSave());

        return redirect()
            ->route('admin.cleaning-plans.index')
            ->with('status', "Temizlik planı eklendi: {$plan->definitionChangeLabel()}");
    }

    public function edit(CleaningPlan $cleaningPlan): View
    {
        return $this->form($cleaningPlan, $cleaningPlan->tasks()->count());
    }

    public function update(SaveCleaningPlanRequest $request, CleaningPlan $cleaningPlan): RedirectResponse
    {
        $cleaningPlan->fill($request->attributesToSave());

        // Görevler planın makinesine aittir; görev üretmiş planın makinesi değişmez.
        if ($cleaningPlan->isDirty('machine_id') && $cleaningPlan->tasks()->exists()) {
            throw ValidationException::withMessages([
                'machine_id' => 'Bu plan görev ürettiği için makinesi değiştirilemez; yeni makine için yeni plan ekleyin.',
            ]);
        }

        $cleaningPlan->save();

        return redirect()
            ->route('admin.cleaning-plans.index')
            ->with('status', "Temizlik planı güncellendi: {$cleaningPlan->definitionChangeLabel()}");
    }

    public function deactivate(CleaningPlan $cleaningPlan): RedirectResponse
    {
        $cleaningPlan->update(['is_active' => false]);

        return redirect()
            ->route('admin.cleaning-plans.index')
            ->with('status', "{$cleaningPlan->definitionChangeLabel()} planı kullanımdan kaldırıldı; yeni görev üretmez. Açık görevi varsa olduğu gibi kalır.");
    }

    public function activate(CleaningPlan $cleaningPlan): RedirectResponse
    {
        if (CleaningPlan::query()->active()->where('machine_id', $cleaningPlan->machine_id)->where('kind', $cleaningPlan->kind)->exists()) {
            throw ValidationException::withMessages([
                'plan' => 'Bu makinede aynı kurallı, kullanımda bir temizlik planı var.',
            ]);
        }

        $cleaningPlan->update(['is_active' => true]);

        return redirect()
            ->route('admin.cleaning-plans.index')
            ->with('status', "{$cleaningPlan->definitionChangeLabel()} planı yeniden kullanımda.");
    }

    private function form(CleaningPlan $plan, int $taskCount): View
    {
        return view('admin.cleaning-plans.form', [
            'plan' => $plan->loadMissing('machine.line.facility', 'activeTask'),
            'taskCount' => $taskCount,
            'kinds' => CleaningPlanKind::cases(),
            'machineGroups' => $this->machineGroups($plan),
        ]);
    }

    /**
     * <optgroup> için "Tesis / Hat" başlığına göre kullanımdaki makineler; düzenlenen planın
     * makinesi kullanımdan kaldırılmış olsa da listelenir.
     *
     * @return BaseCollection<string, Collection<int, Machine>>
     */
    private function machineGroups(CleaningPlan $plan): BaseCollection
    {
        return Machine::query()
            ->join('lines', 'lines.id', '=', 'machines.line_id')
            ->join('facilities', 'facilities.id', '=', 'lines.facility_id')
            ->select('machines.*')
            ->where(fn ($query) => $query->where('machines.is_active', true)->when($plan->machine_id, fn ($query, $id) => $query->orWhere('machines.id', $id)))
            ->orderBy('facilities.code')
            ->orderBy('lines.code')
            ->orderBy('machines.code')
            ->with('line.facility')
            ->get()
            ->groupBy(fn (Machine $machine) => "{$machine->line->facility->name} / {$machine->line->name}");
    }
}

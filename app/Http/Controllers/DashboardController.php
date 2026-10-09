<?php

namespace App\Http\Controllers;

use App\Enums\CleaningStatus;
use App\Enums\StepStatus;
use App\Models\Cleaning;
use App\Models\CleaningPhase;
use App\Models\CleaningStep;
use App\Models\User;
use App\Services\Cleaning\WorkTime;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * Gösterge paneli: özet sayaçlar ve açık kayıtlar tablosu. Operatör ve yönetici aynı sayfayı
 * görür ve sayfa salt okunurdur (K-11). Görmek çalıştırmak değildir (R-44); kullanıcının
 * işlem yapabileceği satırlar (sahibi ya da güncel adımın görevlisi) işaretlenir.
 */
class DashboardController extends Controller
{
    /** Minimum süre altında kapanan faz sayacının geriye baktığı gün sayısı (K-01). */
    private const BELOW_MINIMUM_DAYS = 30;

    public function index(Request $request): View
    {
        $today = now(config('app.display_timezone'))->startOfDay();
        $openCleanings = $this->openCleanings();
        $openCounts = $openCleanings->countBy(fn (Cleaning $cleaning) => $cleaning->status->value);

        return view('dashboard', [
            'today' => $today,
            'belowMinimumDays' => self::BELOW_MINIMUM_DAYS,
            'stats' => [
                'created' => $openCounts[CleaningStatus::Created->value] ?? 0,
                'in_progress' => $openCounts[CleaningStatus::InProgress->value] ?? 0,
                'completed_today' => $this->completedOn($today),
                'below_minimum' => $this->phasesBelowMinimum(),
            ],
            'rows' => $openCleanings->map(fn (Cleaning $cleaning) => $this->row($cleaning, $request->user()))->values(),
        ]);
    }

    /**
     * Başlamamış ve devam eden kayıtlar, son hareketi en yeni olan önce. Son hareket, kaydın
     * olay zincirindeki en son olaydır (açılış, adım başlatma/duraklatma, görevli değişikliği…).
     *
     * @return Collection<int, Cleaning>
     */
    private function openCleanings(): Collection
    {
        return Cleaning::query()
            ->whereIn('status', [CleaningStatus::Created, CleaningStatus::InProgress])
            ->with([
                'facility',
                'line',
                'machine',
                'owner',
                // Güncel adım: tamamlanmamış ilk adım. Adımlar sırayla yürüdüğü için çalışan ya da
                // duraklatılmış adım varsa odur; yoksa sıradaki bekleyen adımdır.
                'steps' => fn ($query) => $query
                    ->where('status', '!=', StepStatus::Completed)
                    ->limit(1)
                    ->with(['procedureStep', 'activeAssignees']),
                // Net süre tek sorguda yüklenen dilimlerden hesaplanır (K-02).
                'slices',
            ])
            ->withMax('events as last_activity_at', 'occurred_at')
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Gösterim saat dilimine göre verilen günde tamamlanan kayıtlar. Zamanlar UTC saklanır.
     */
    private function completedOn(CarbonInterface $day): int
    {
        $dbTimezone = config('app.timezone');

        return Cleaning::query()
            ->where('status', CleaningStatus::Completed)
            ->where('closed_at', '>=', $day->copy()->setTimezone($dbTimezone))
            ->where('closed_at', '<', $day->copy()->addDay()->setTimezone($dbTimezone))
            ->count();
    }

    /**
     * Son günlerde minimum süresinin altında (gerekçeyle) kapanan fazlar (K-01).
     */
    private function phasesBelowMinimum(): int
    {
        return CleaningPhase::query()
            ->where('below_minimum', true)
            ->where('completed_at', '>=', now()->subDays(self::BELOW_MINIMUM_DAYS))
            ->count();
    }

    /**
     * @return array{cleaning: Cleaning, step: ?CleaningStep, netSeconds: ?int, mine: ?string}
     */
    private function row(Cleaning $cleaning, User $user): array
    {
        $step = $cleaning->steps->first();

        return [
            'cleaning' => $cleaning,
            'step' => $step,
            // Başlamamış kayıtta süre yoktur; başlamışta duraklamalar ve adım arası boşluklar sayılmaz.
            'netSeconds' => $cleaning->started_at === null ? null : WorkTime::net($cleaning->slices),
            'mine' => $this->mineReason($cleaning, $step, $user),
        ];
    }

    /**
     * R-44, K-11: kayıtta işlem yapabilecek kişi kaydın sahibi ya da güncel adımın aktif
     * görevlisidir. Yönetici rolü burada ayrıcalık vermez.
     *
     * @return 'owner'|'assignee'|null
     */
    private function mineReason(Cleaning $cleaning, ?CleaningStep $step, User $user): ?string
    {
        if ($cleaning->isOwnedBy($user)) {
            return 'owner';
        }

        if ($step?->activeAssignees->contains('user_id', $user->id)) {
            return 'assignee';
        }

        return null;
    }
}

<?php

namespace App\Services\Reports;

use App\Models\CleaningEvent;
use App\Models\CleaningPhase;
use App\Models\User;
use App\Models\WorkSlice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Sapmalar raporu:
 * - K-01: minimum süresinin altında (gerekçeyle) kapanan fazlar; tarih aralığı fazın kapanış
 *   zamanına uygulanır. Kaydın sonradan iptal edilmiş olması sapmayı ortadan kaldırmaz.
 * - K-03: cleaning.slice_anomaly_hours eşiğini aşan çalışma dilimleri; tarih aralığı dilimin
 *   başlangıcına uygulanır. Hâlâ açık olan dilim şu ana kadar geçen süreyle değerlendirilir
 *   (duraklatılması unutulmuş adım). Kayıtlar değiştirilmez, yalnızca işaretlenir.
 */
final class DeviationReport
{
    /**
     * @return LengthAwarePaginator<int, CleaningPhase>
     */
    public function belowMinimum(ReportFilters $filters, int $perPage, string $pageName): LengthAwarePaginator
    {
        // Fazı kapatan kişi, fazın phase.completed olayını yazan kişidir (R-49).
        $closedBy = CleaningEvent::query()
            ->select('actor_id')
            ->whereColumn('cleaning_events.cleaning_id', 'cleaning_phases.cleaning_id')
            ->where('cleaning_events.type', 'phase.completed')
            ->whereRaw('json_extract(cleaning_events.payload, \'$.phase_id\') = cleaning_phases.id')
            ->limit(1);

        $phases = CleaningPhase::query()
            ->join('cleanings as c', 'c.id', '=', 'cleaning_phases.cleaning_id')
            ->where('cleaning_phases.below_minimum', true)
            ->select('cleaning_phases.*')
            ->selectSub($closedBy, 'closed_by_id')
            ->with(['cleaning.facility', 'cleaning.line', 'cleaning.machine', 'procedurePhase'])
            ->tap(fn (Builder $query) => $filters->applyToCleanings($query, 'c'))
            ->tap(fn (Builder $query) => $filters->applyDateRange($query, 'cleaning_phases.completed_at'))
            ->orderByDesc('cleaning_phases.completed_at')
            ->orderByDesc('cleaning_phases.id')
            ->paginate($perPage, pageName: $pageName)
            ->withQueryString();

        $users = $this->users($phases->getCollection()->pluck('closed_by_id'));

        $phases->getCollection()->each(fn (CleaningPhase $phase) => $phase->setRelation(
            'closedBy',
            $users->get((int) $phase->closed_by_id),
        ));

        return $phases;
    }

    /**
     * @return LengthAwarePaginator<int, WorkSlice>
     */
    public function anomalousSlices(ReportFilters $filters, int $perPage, string $pageName): LengthAwarePaginator
    {
        $now = now()->format('Y-m-d H:i:s');
        $duration = 'timestampdiff(second, work_slices.started_at, coalesce(work_slices.ended_at, ?))';

        return WorkSlice::query()
            ->join('cleaning_steps as cs', 'cs.id', '=', 'work_slices.cleaning_step_id')
            ->join('cleanings as c', 'c.id', '=', 'cs.cleaning_id')
            ->select('work_slices.*')
            ->selectRaw("{$duration} as duration_seconds", [$now])
            ->whereRaw("{$duration} > ?", [$now, $this->thresholdSeconds()])
            ->with([
                'step.procedureStep',
                'step.cleaning.facility',
                'step.cleaning.line',
                'step.cleaning.machine',
                'workers.user',
            ])
            ->tap(fn (Builder $query) => $filters->applyToCleanings($query, 'c'))
            ->tap(fn (Builder $query) => $filters->applyDateRange($query, 'work_slices.started_at'))
            ->orderByDesc('work_slices.started_at')
            ->orderByDesc('work_slices.id')
            ->paginate($perPage, pageName: $pageName)
            ->withQueryString();
    }

    public function thresholdHours(): int
    {
        return (int) config('cleaning.slice_anomaly_hours');
    }

    private function thresholdSeconds(): int
    {
        return $this->thresholdHours() * 3600;
    }

    /**
     * @param  Collection<int, mixed>  $ids
     * @return Collection<int, User>
     */
    private function users(Collection $ids): Collection
    {
        $ids = $ids->filter()->map(fn ($id) => (int) $id)->unique()->values();

        return $ids->isEmpty()
            ? new Collection
            : User::query()->whereIn('id', $ids)->get()->keyBy('id')->toBase();
    }
}

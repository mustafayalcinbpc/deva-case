<?php

namespace App\Services\Reports;

use App\Enums\PhaseStatus;
use App\Enums\SliceEndReason;
use App\Models\Cleaning;
use App\Models\CleaningEvent;
use App\Models\CleaningMaterial;
use App\Models\User;
use App\Models\WorkSlice;
use App\Services\Cleaning\CleaningEventDescriber;
use App\Services\Cleaning\CleaningEventRecorder;
use App\Services\Cleaning\WorkTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Kayıt bazında denetim raporu (R-45–R-49). R-45'teki her sorunun cevabı (nerede, kim, kimler
 * hangi adımda, ne zaman, ne kadar sürede, ne kadar eforla, hangi malzeme ve lotla, hangi
 * prosedür versiyonuyla, hangi üretim iş emriyle, ne zaman tamamlandı) ve olay zincirinin tamamı
 * hash'leriyle; zincir baştan hesaplanarak doğrulanır (R-46).
 *
 * Aynı veri hem yazdırılabilir sayfada hem kuyrukta üretilen PDF'te kullanılır.
 */
final class AuditReport
{
    public function __construct(
        private readonly CleaningEventRecorder $recorder,
        private readonly CleaningEventDescriber $describer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Cleaning $cleaning, ?User $generatedBy = null): array
    {
        $this->load($cleaning);

        $users = $this->referencedUsers($cleaning);
        $slices = $cleaning->steps->flatMap->slices;
        $startedEvent = $cleaning->events->firstWhere('type', 'cleaning.started');

        return [
            'cleaning' => $cleaning,
            'users' => $users,
            'startedBy' => $startedEvent ? $users->get((int) $startedEvent->actor_id) : null,
            'totals' => [
                'started' => $slices->isNotEmpty(),
                'net' => WorkTime::net($slices),
                'gross' => WorkTime::gross($slices),
                'effort' => WorkTime::effort($slices),
                'sliceCount' => $slices->count(),
                // Çalışan bir adım varsa süreler rapor anına kadar sayılır.
                'running' => $slices->contains(fn (WorkSlice $slice) => $slice->ended_at === null),
            ],
            'phases' => $this->phases($cleaning, $users),
            'materials' => $cleaning->materials->map(fn (CleaningMaterial $item) => [
                'item' => $item,
                'addedBy' => $this->name($users, $item->added_by),
                'voidedBy' => $item->voided_by === null ? null : $this->name($users, $item->voided_by),
            ])->all(),
            'events' => $cleaning->events->map(fn (CleaningEvent $event) => [
                'event' => $event,
                'actor' => $event->actor_id === null ? 'Sistem' : $this->name($users, $event->actor_id),
                ...$this->describer->describe($event, $cleaning, $users),
            ])->all(),
            'chainIntact' => $this->recorder->verifyEvents($cleaning->events),
            'generatedAt' => CarbonImmutable::now(),
            'generatedBy' => $generatedBy,
        ];
    }

    /**
     * Raporun ihtiyaç duyduğu her şey tek seferde; fazın adımları kaydın sıralı adım
     * listesinden dağıtılır, sorgu sayısı adım sayısıyla büyümez.
     */
    private function load(Cleaning $cleaning): void
    {
        $cleaning->load([
            'facility',
            'line',
            'machine',
            'owner',
            'workOrder',
            'procedureVersion.procedure',
            'phases.procedurePhase',
            'steps.procedureStep',
            'steps.slices' => fn ($query) => $query->withCount('workers')->with('workers'),
            'materials' => fn ($query) => $query->with('material')->orderBy('id'),
            'events',
        ]);

        $stepsByPhase = $cleaning->steps->groupBy('cleaning_phase_id');

        foreach ($cleaning->phases as $phase) {
            $phase->setRelation('steps', new EloquentCollection($stepsByPhase->get($phase->id, [])));
        }
    }

    /**
     * Faz başına minimum ve ölçülen süre, sapma; adım başına başlangıç, bitiş, süre, efor,
     * çalışan kişiler ve çalışma dilimleri (R-45 4–8, K-01–K-04).
     *
     * @param  Collection<int, User>  $users
     * @return list<array<string, mixed>>
     */
    private function phases(Cleaning $cleaning, Collection $users): array
    {
        return $cleaning->phases->map(fn ($phase) => [
            'phase' => $phase,
            'minimum' => (int) $phase->procedurePhase->min_duration_seconds,
            'includeGaps' => (bool) $phase->procedurePhase->include_gaps,
            'measured' => $phase->status === PhaseStatus::Completed ? (int) $phase->measured_seconds : null,
            'steps' => $phase->steps->map(fn ($step) => [
                'step' => $step,
                'net' => WorkTime::net($step->slices),
                'effort' => WorkTime::effort($step->slices),
                'workers' => $step->slices
                    ->flatMap(fn (WorkSlice $slice) => $slice->workers->pluck('user_id'))
                    ->unique()
                    ->map(fn ($id) => $this->name($users, $id))
                    ->values()
                    ->all(),
                'slices' => $step->slices->map(fn (WorkSlice $slice) => [
                    'slice' => $slice,
                    'seconds' => $slice->durationSeconds(),
                    'workers' => $slice->workers->map(fn ($worker) => $this->name($users, $worker->user_id))->all(),
                    'endReason' => $this->endReason($slice->end_reason),
                ])->all(),
            ])->all(),
        ])->all();
    }

    /**
     * Raporda adı geçen bütün kişiler tek sorguda.
     *
     * @return Collection<int, User>
     */
    private function referencedUsers(Cleaning $cleaning): Collection
    {
        $ids = collect([$cleaning->owner_id, $cleaning->cancelled_by]);

        foreach ($cleaning->steps as $step) {
            foreach ($step->slices as $slice) {
                $ids->push(...$slice->workers->pluck('user_id'));
            }
        }

        foreach ($cleaning->materials as $item) {
            $ids->push($item->added_by, $item->voided_by);
        }

        foreach ($cleaning->events as $event) {
            $payload = $event->payload ?? [];
            $ids->push($event->actor_id);

            foreach (['helper_ids', 'worker_ids', 'added', 'removed'] as $key) {
                $ids->push(...(array) ($payload[$key] ?? []));
            }
        }

        $ids = $ids->filter()->map(fn ($id) => (int) $id)->unique()->values();

        return $ids->isEmpty()
            ? new Collection
            : User::query()->whereIn('id', $ids)->get()->keyBy('id')->toBase();
    }

    /**
     * @param  Collection<int, User>  $users
     */
    private function name(Collection $users, mixed $id): string
    {
        return $users->get((int) $id)?->name ?? "#{$id}";
    }

    private function endReason(?SliceEndReason $reason): ?string
    {
        return match ($reason) {
            SliceEndReason::Paused => 'Duraklatıldı',
            SliceEndReason::WorkersChanged => 'Görevliler değişti',
            SliceEndReason::Completed => 'Adım tamamlandı',
            SliceEndReason::Cancelled => 'Kayıt iptal edildi',
            null => null,
        };
    }
}

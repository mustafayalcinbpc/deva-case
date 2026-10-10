<?php

namespace App\Http\Controllers;

use App\Enums\PhaseStatus;
use App\Enums\StepStatus;
use App\Models\Cleaning;
use App\Models\CleaningEvent;
use App\Models\CleaningMaterial;
use App\Models\CleaningStep;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\ProcedureVersionMaterial;
use App\Models\User;
use App\Models\WorkSlice;
use App\Services\Cleaning\CleaningEventDescriber;
use App\Services\Cleaning\CleaningEventRecorder;
use App\Services\Cleaning\CleaningPermissions;
use App\Services\Cleaning\WorkTime;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Temizlik kaydının detay ekranı: sahada adım adım ilerlenen asıl ekran (R-21–R-25).
 *
 * Sayfa veri değiştirmez; aksiyon formları ayrı controller'lara gider ve kuralı workflow
 * uygular. Bu controller yalnızca hangi formun gösterileceğine CleaningPermissions ile karar
 * verir (R-44, K-08–K-11) ve süreleri yüklenmiş çalışma dilimlerinden hesaplar (R-26, K-02).
 * Bütün ilişkiler baştan yüklenir; sorgu sayısı adım sayısıyla büyümez.
 */
class CleaningDetailController extends Controller
{
    /** Tarayıcıda video olarak gösterilecek medya uzantıları; diğerleri görsel sayılır (R-05). */
    private const VIDEO_EXTENSIONS = ['mp4', 'webm', 'ogg', 'ogv', 'mov', 'm4v'];

    public function __construct(
        private readonly CleaningPermissions $permissions,
        private readonly CleaningEventRecorder $recorder,
        private readonly CleaningEventDescriber $describer,
    ) {}

    public function show(Request $request, Cleaning $cleaning): View
    {
        /** @var User $user */
        $user = $request->user();

        $this->loadRecord($cleaning);

        $users = $this->referencedUsers($cleaning);
        $current = $this->currentStep($cleaning);
        $canOperate = $current !== null && $this->permissions->canOperateStep($user, $cleaning, $current);
        $canManageMaterials = $cleaning->status->isOpen() && $this->permissions->canManageMaterials($user, $cleaning);

        return view('cleanings.show', [
            'cleaning' => $cleaning,
            'users' => $users,
            'totals' => $this->times($cleaning->steps->flatMap->slices),
            'phaseRows' => $this->phaseRows($cleaning),
            'stepRows' => $this->stepRows($cleaning, $users),
            'current' => $current,
            'currentPhase' => $current ? $cleaning->phases->firstWhere('id', $current->cleaning_phase_id) : null,
            'deviation' => $current ? $this->deviationFor($request, $current) : null,
            'canOperate' => $canOperate,
            'workerChoices' => $canOperate ? $this->workerChoices() : new EloquentCollection,
            'canManageMaterials' => $canManageMaterials,
            // K-14: eklenebilen lotlar (lot ve malzemesi kullanımda, SKT geçmemiş), malzemeye göre gruplu.
            // Kayıtta zaten olanlar görünmeye devam eder (K-13).
            'lotCatalog' => $canManageMaterials ? $this->lotCatalog() : collect(),
            ...$this->materialRequirement($cleaning),
            'cancelReasons' => $this->permissions->allowedCancelReasons($user, $cleaning),
            'history' => $this->history($cleaning, $users),
            'chainIntact' => $this->recorder->verifyEvents($cleaning->events),
        ]);
    }

    /**
     * Ekranın ihtiyaç duyduğu her şey tek seferde yüklenir. Fazın adımları ayrıca sorgulanmaz;
     * kaydın sıralı adım listesi fazlara dağıtılır.
     */
    private function loadRecord(Cleaning $cleaning): void
    {
        $cleaning->load([
            'facility',
            'line',
            'machine',
            'owner',
            'workOrder',
            // K-21: kaydın açıldığı görev ve görevi doğuran üretim iş emri (Özet, olay geçmişi).
            'task.triggerWorkOrder',
            'procedureVersion.procedure',
            'procedureVersion.materials.material',
            'phases.procedurePhase',
            'steps.procedureStep',
            'steps.activeAssignees',
            // Efor için dilimdeki kişi sayısı, "kimler çalıştı" için kişiler (R-26, R-45).
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
     * Ekranda adı geçen bütün kişiler tek sorguda: görevliler, dilimlerde çalışanlar, malzemeyi
     * ekleyen/geçersiz kılan, iptal eden ve olayların yapanları ile olaylardaki kişi listeleri.
     *
     * @return Collection<int, User>
     */
    private function referencedUsers(Cleaning $cleaning): Collection
    {
        $ids = collect([$cleaning->owner_id, $cleaning->cancelled_by]);

        foreach ($cleaning->steps as $step) {
            $ids->push(...$step->activeAssignees->pluck('user_id'));

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
     * Güncel adım: çalışan ya da duraklatılmış adım; yoksa sıradaki bekleyen adım. Adımlar
     * sırayla yürüdüğü için bu, tamamlanmamış ilk adımdır. Kapalı kayıtta güncel adım yoktur.
     */
    private function currentStep(Cleaning $cleaning): ?CleaningStep
    {
        if (! $cleaning->status->isOpen()) {
            return null;
        }

        return $cleaning->steps->first(fn (CleaningStep $step) => in_array($step->status, [StepStatus::Running, StepStatus::Paused], true))
            ?? $cleaning->steps->first(fn (CleaningStep $step) => $step->status === StepStatus::Pending);
    }

    /**
     * Faz başına minimum süre ve ölçülen süre (K-01, K-02). Tamamlanmış fazda kapanışta kaydedilen
     * süre, devam eden fazda şu ana kadarki süre gösterilir.
     *
     * @return array<int, array{minimum: int, includeGaps: bool, measured: ?int, live: ?array<string, mixed>}>
     */
    private function phaseRows(Cleaning $cleaning): array
    {
        $rows = [];

        foreach ($cleaning->phases as $phase) {
            $includeGaps = (bool) $phase->procedurePhase->include_gaps;
            $times = $this->times($phase->steps->flatMap->slices);
            $measuredKey = $includeGaps ? 'gross' : 'net';

            $rows[$phase->id] = [
                'minimum' => (int) $phase->procedurePhase->min_duration_seconds,
                'includeGaps' => $includeGaps,
                'measured' => match (true) {
                    $phase->status === PhaseStatus::Completed => (int) $phase->measured_seconds,
                    $times['started'] => $times[$measuredKey],
                    default => null,
                },
                'live' => $phase->status === PhaseStatus::Completed ? null : $times['live'][$measuredKey],
            ];
        }

        return $rows;
    }

    /**
     * Adım başına süre, efor, görevliler ve medya (R-05, R-22, R-26).
     *
     * @param  Collection<int, User>  $users
     * @return array<int, array<string, mixed>>
     */
    private function stepRows(Cleaning $cleaning, Collection $users): array
    {
        $rows = [];

        foreach ($cleaning->phases as $phase) {
            foreach ($phase->steps as $index => $step) {
                $workedBy = $step->slices
                    ->flatMap(fn (WorkSlice $slice) => $slice->workers->pluck('user_id'))
                    ->unique();

                $rows[$step->id] = [
                    'times' => $this->times($step->slices),
                    'sliceCount' => $step->slices->count(),
                    'assignees' => $this->names($users, $step->activeAssignees->pluck('user_id')->all()),
                    'assigneeIds' => $step->activeAssignees->pluck('user_id')->map(fn ($id) => (int) $id)->all(),
                    'workedBy' => $this->names($users, $workedBy->all()),
                    'media' => $this->media($step->procedureStep->media_path),
                    'position' => $index + 1,
                    'phaseStepCount' => $phase->steps->count(),
                    'isLastOfPhase' => $index === $phase->steps->count() - 1,
                ];
            }
        }

        return $rows;
    }

    /**
     * Net süre, brüt süre ve efor (R-26, K-02, K-04). Açık dilim varsa tarayıcıda sayaç olarak
     * ilerleyebilmesi için dilimin başlangıcı ve kapanmış dilimlerin toplamı da verilir.
     *
     * @param  iterable<WorkSlice>  $slices
     * @return array{started: bool, net: int, gross: int, effort: int, live: array{net: ?array<string, mixed>, gross: ?array<string, mixed>, effort: ?array<string, mixed>}}
     */
    private function times(iterable $slices): array
    {
        $slices = collect($slices);
        $open = $slices->first(fn (WorkSlice $slice) => $slice->ended_at === null);
        $closed = $slices->reject(fn (WorkSlice $slice) => $slice->ended_at === null);
        $first = $slices->sortBy(fn (WorkSlice $slice) => $slice->started_at->getTimestamp())->first();

        return [
            'started' => $slices->isNotEmpty(),
            'net' => WorkTime::net($slices),
            'gross' => WorkTime::gross($slices),
            'effort' => WorkTime::effort($slices),
            'live' => [
                'net' => $open ? $this->ticker($open->started_at, WorkTime::net($closed)) : null,
                // Açık dilimde brüt süre = ilk dilimin başlangıcından şu ana kadar.
                'gross' => $open ? $this->ticker($open->started_at, (int) $first->started_at->diffInSeconds($open->started_at)) : null,
                'effort' => $open ? $this->ticker($open->started_at, WorkTime::effort($closed), $open->workerCount()) : null,
            ],
        ];
    }

    /**
     * Canlı sayaç verisi (resources/js/modules/live-duration.js): değer = base + rate × (şimdi − startedAt).
     * Sunucu saati de verilir; tarayıcı saatindeki kayma sayaca yansımaz.
     *
     * @return array{startedAt: string, base: int, rate: int, serverNow: string}
     */
    private function ticker(CarbonInterface $startedAt, int $base, int $rate = 1): array
    {
        return [
            'startedAt' => $startedAt->toIso8601String(),
            'base' => $base,
            'rate' => $rate,
            'serverNow' => now()->toIso8601String(),
        ];
    }

    /**
     * K-01: workflow fazı minimum sürenin altında bulup gerekçe istediyse (session('violation')),
     * o fazın adımında gerekçe alanı açık gösterilir.
     *
     * @return array{measured: int, minimum: int}|null
     */
    private function deviationFor(Request $request, CleaningStep $step): ?array
    {
        $violation = $request->session()->get('violation');

        if (! is_array($violation)
            || ($violation['rule'] ?? null) !== 'below_minimum_duration'
            || (int) ($violation['context']['phase_id'] ?? 0) !== (int) $step->cleaning_phase_id) {
            return null;
        }

        return [
            'measured' => (int) ($violation['context']['measured_seconds'] ?? 0),
            'minimum' => (int) ($violation['context']['minimum_seconds'] ?? 0),
        ];
    }

    /**
     * Görevli seçimi için aktif kullanıcılar (R-22). Pasif kullanıcı görevli olamaz.
     *
     * @return EloquentCollection<int, User>
     */
    private function workerChoices(): EloquentCollection
    {
        return User::query()->where('is_active', true)->orderBy('name')->get();
    }

    /**
     * @return array{url: string, type: 'image'|'video'}|null
     */
    private function media(?string $path): ?array
    {
        if ($path === null || $path === '') {
            return null;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return [
            'url' => Storage::disk('public')->url($path),
            'type' => in_array($extension, self::VIDEO_EXTENSIONS, true) ? 'video' : 'image',
        ];
    }

    /**
     * @param  Collection<int, User>  $users
     * @param  array<int|string>  $ids
     * @return list<string>
     */
    private function names(Collection $users, array $ids): array
    {
        return array_values(array_map(
            fn ($id) => $users->get((int) $id)?->name ?? "#{$id}",
            array_filter($ids, fn ($id) => $id !== null),
        ));
    }

    // ---------------------------------------------------------------------------------------
    // Malzemeler (K-12–K-14)
    // ---------------------------------------------------------------------------------------

    /**
     * Prosedürün beklediği malzemeler ve kayıttaki geçerli girişleri; ilk adımı engelleyen eksikler.
     * Kural CleaningWorkflow::assertRequiredMaterials ile aynıdır: listede zorunlu malzeme varsa her
     * biri için geçerli giriş gerekir; listesi olmayan eski versiyonda material_required en az bir
     * geçerli giriş ister.
     *
     * @return array{expectedMaterials: list<array{material: Material, required: bool, lots: list<string>}>, missingMaterials: Collection<int, Material>, materialMissing: bool}
     */
    private function materialRequirement(Cleaning $cleaning): array
    {
        $version = $cleaning->procedureVersion;
        $valid = $cleaning->materials->filter(fn (CleaningMaterial $item) => $item->voided_at === null);
        $lotsByMaterial = $valid->groupBy('material_id');

        $expected = $version->materials->map(fn (ProcedureVersionMaterial $item) => [
            'material' => $item->material,
            'required' => $item->is_required,
            'lots' => $lotsByMaterial->get($item->material_id, collect())->pluck('lot_no')->unique()->values()->all(),
        ]);

        $missing = $expected
            ->filter(fn (array $row) => $row['required'] && $row['lots'] === [])
            ->map(fn (array $row) => $row['material'])
            ->values()
            ->toBase();

        return [
            'expectedMaterials' => $expected->values()->all(),
            'missingMaterials' => $missing,
            'materialMissing' => $expected->contains(fn (array $row) => $row['required'])
                ? $missing->isNotEmpty()
                : $version->material_required && $valid->isEmpty(),
        ];
    }

    /**
     * @return Collection<int, EloquentCollection<int, MaterialLot>>
     */
    private function lotCatalog(): Collection
    {
        // Malzeme koduna göre sıralama PHP'de: sıralama kararlıdır, lot içi sıra (SKT, lot no) korunur.
        return MaterialLot::query()
            ->usableOn(now())
            ->with('material')
            ->orderBy('expiry_date')
            ->orderBy('lot_no')
            ->get()
            ->sortBy(fn (MaterialLot $lot) => $lot->material->code, SORT_STRING)
            ->groupBy('material_id')
            ->toBase();
    }

    // ---------------------------------------------------------------------------------------
    // Olay geçmişi (R-45–R-49)
    // ---------------------------------------------------------------------------------------

    /**
     * Olay zinciri kronolojik sırayla, her olay için Türkçe açıklama ve önemli ayrıntılar.
     *
     * @param  Collection<int, User>  $users
     * @return list<array{event: CleaningEvent, actor: string, system: bool, title: string, details: list<array{label: string, value?: string, seconds?: int}>}>
     */
    private function history(Cleaning $cleaning, Collection $users): array
    {
        // Metin denetim raporuyla ortaktır (CleaningEventDescriber).
        return $cleaning->events->map(function (CleaningEvent $event) use ($cleaning, $users) {
            ['title' => $title, 'details' => $details] = $this->describer->describe($event, $cleaning, $users);

            return [
                'event' => $event,
                'actor' => $event->actor_id === null ? 'Sistem' : ($users->get($event->actor_id)?->name ?? "#{$event->actor_id}"),
                'system' => $event->actor_id === null,
                'title' => $title,
                'details' => $details,
            ];
        })->all();
    }
}

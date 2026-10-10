<?php

namespace App\Http\Controllers;

use App\Enums\CleaningStatus;
use App\Enums\CleaningType;
use App\Http\Requests\StoreCleaningRequest;
use App\Models\Cleaning;
use App\Models\CleaningTask;
use App\Models\Machine;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Cleaning\CleaningWorkflow;
use App\Services\Cleaning\WorkTime;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection as BaseCollection;

/**
 * Kayıt listesi ve kayıt açma (R-14–R-20). Liste herkese açıktır (K-11). Form yalnızca
 * seçilebilecek olanı gösterir (R-02); asıl kontrol CleaningWorkflow::open() içindedir ve
 * kural ihlali merkezi olarak forma geri döner.
 */
class CleaningController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $user = $request->user();

        $cleanings = Cleaning::query()
            ->when($filters['status'], fn (Builder $query, CleaningStatus $status) => $query->where('status', $status))
            ->when($filters['machine_id'], fn (Builder $query, int $machineId) => $query->where('machine_id', $machineId))
            // Kaydın sahibi ya da herhangi bir adımının aktif görevlisi (R-44, K-11).
            ->when($filters['mine'], fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('owner_id', $user->id)
                ->orWhereHas('steps.activeAssignees', fn (Builder $query) => $query->where('user_id', $user->id))))
            // Net süre tek sorguda yüklenen dilimlerden hesaplanır (K-02).
            ->with(['facility', 'line', 'machine', 'owner', 'slices'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Cleaning $cleaning) => [
                'cleaning' => $cleaning,
                // Başlamamış kayıtta süre yoktur; başlamışta duraklamalar ve adım arası boşluklar sayılmaz.
                'netSeconds' => $cleaning->started_at === null ? null : WorkTime::net($cleaning->slices),
            ]);

        return view('cleanings.index', [
            'cleanings' => $cleanings,
            'filters' => $filters,
            'isFiltered' => $filters['status'] !== null || $filters['machine_id'] !== null || $filters['mine'],
            'statuses' => CleaningStatus::cases(),
            // Geçmiş kayıtlar için kullanımdan kaldırılmış makineler de filtrelenebilir (K-16).
            'machineGroups' => $this->groupByLocation($this->machinesQuery()->get()),
        ]);
    }

    public function create(Request $request): View
    {
        return view('cleanings.create', [
            // K-21: görevden gelindiyse (?task=ID) form görevle doldurulur; görev açık değilse yok sayılır.
            'task' => $this->openTask($request),
            'machineGroups' => $this->groupByLocation($this->usableMachines()),
            'types' => CleaningType::cases(),
            'helpers' => User::query()
                ->where('is_active', true)
                ->whereKeyNot($request->user()->id)
                ->orderBy('name')
                ->get(['id', 'name']),
            'workOrders' => $this->workOrderOptions(),
            // Kullanımdan kaldırılan malzeme yeni kayıtta seçilemez (K-13).
            'materials' => Material::query()->active()->orderBy('code')->get(['id', 'code', 'name']),
            // K-14: seçilebilen lotlar (kullanımda, SKT'si geçmemiş), malzemeye göre gruplu.
            'lotsByMaterial' => MaterialLot::query()
                ->usableOn(now())
                ->orderBy('expiry_date')
                ->orderBy('lot_no')
                ->get(['id', 'material_id', 'lot_no', 'expiry_date'])
                ->groupBy('material_id'),
        ]);
    }

    private function openTask(Request $request): ?CleaningTask
    {
        $id = $request->integer('task');

        return $id > 0
            ? CleaningTask::query()->open()->with(['machine', 'workOrder', 'triggerWorkOrder'])->find($id)
            : null;
    }

    public function store(StoreCleaningRequest $request, CleaningWorkflow $workflow): RedirectResponse
    {
        $cleaning = $workflow->open(
            $request->user(),
            $request->machine(),
            $request->cleaningType(),
            $request->helperIds(),
            $request->materialEntries(),
            $request->workOrder(),
            $request->notes(),
            $request->task(),
        );

        return redirect()
            ->route('cleanings.show', $cleaning)
            ->with('status', "Kayıt açıldı: {$cleaning->record_no}");
    }

    /**
     * Geçersiz filtre değerleri yok sayılır.
     *
     * @return array{status: ?CleaningStatus, machine_id: ?int, mine: bool}
     */
    private function filters(Request $request): array
    {
        $status = $request->query('status');
        $machineId = filter_var($request->query('machine_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return [
            'status' => is_string($status) ? CleaningStatus::tryFrom($status) : null,
            'machine_id' => $machineId === false ? null : $machineId,
            'mine' => $request->boolean('mine'),
        ];
    }

    /**
     * Makineler tesis, hat ve makine koduna göre sıralı.
     *
     * @return Builder<Machine>
     */
    private function machinesQuery(): Builder
    {
        return Machine::query()
            ->join('lines', 'lines.id', '=', 'machines.line_id')
            ->join('facilities', 'facilities.id', '=', 'lines.facility_id')
            ->select('machines.*')
            ->orderBy('facilities.code')
            ->orderBy('lines.code')
            ->orderBy('machines.code')
            ->with('line.facility');
    }

    /**
     * R-02, K-16, K-18: kayıt yalnızca kullanımda olan ve prosedürünün yayımlanmış geçerli bir
     * versiyonu bulunan makine için açılabilir. Her makineye o versiyonun özeti (fazlar, adım
     * sayıları, minimum süreler, malzeme zorunluluğu) ve aynı makinedeki başlamamış kayıtlar
     * (K-05 uyarısı) eklenir.
     *
     * @return Collection<int, Machine>
     */
    private function usableMachines(): Collection
    {
        $machines = $this->machinesQuery()
            ->where('machines.is_active', true)
            ->whereNotNull('machines.procedure_id')
            // Geçerli versiyon eager load ile gelir; sorgu sayısı makine ya da prosedür sayısıyla artmaz.
            ->with([
                'procedure.currentPublishedVersion.phases' => fn ($query) => $query->withCount('steps'),
                // K-13: formdaki malzeme satırları versiyonun beklediği malzemelerden gelir.
                'procedure.currentPublishedVersion.materials.material',
            ])
            ->get();

        // K-05: kayıt açmak makineyi kilitlemez, ama aynı makinede başlamamış kayıt varsa uyarılır.
        $pending = Cleaning::query()
            ->pendingOn($machines)
            ->with('owner:id,name')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'record_no', 'machine_id', 'owner_id', 'created_at'])
            ->groupBy('machine_id');

        return $machines
            ->filter(fn (Machine $machine) => $machine->procedure?->currentPublishedVersion !== null)
            ->each(fn (Machine $machine) => $machine
                ->setRelation('currentVersion', $machine->procedure->currentPublishedVersion)
                ->setRelation('pendingCleanings', $pending->get($machine->id, new Collection)))
            ->values();
    }

    /**
     * <optgroup> için "Tesis / Hat" başlığına göre gruplanmış makineler.
     *
     * @param  Collection<int, Machine>  $machines
     * @return BaseCollection<string, Collection<int, Machine>>
     */
    private function groupByLocation(Collection $machines): BaseCollection
    {
        return $machines->groupBy(fn (Machine $machine) => "{$machine->line->facility->name} / {$machine->line->name}");
    }

    /**
     * K-19: bütün üretim iş emirleri, bağlı oldukları makine ya da hatla birlikte. Seçilen makineye
     * ait olmayanları form gizler; asıl kontrol workflow'dadır (invalid_work_order).
     *
     * @return BaseCollection<int, array{id: int, label: string, machine_id: ?int, line_id: ?int}>
     */
    private function workOrderOptions(): BaseCollection
    {
        return WorkOrder::query()
            ->with(['machine.line.facility', 'line.facility'])
            ->orderBy('code')
            ->get()
            ->map(fn (WorkOrder $workOrder) => [
                'id' => $workOrder->id,
                'label' => collect([$workOrder->code, $workOrder->description])->filter()->implode(' — ')
                    .' ('.($workOrder->locationCodes() ?? 'bütün makineler').')',
                'machine_id' => $workOrder->machine_id,
                'line_id' => $workOrder->line_id,
            ]);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CleaningStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Locations\MachineRequest;
use App\Models\Cleaning;
use App\Models\Line;
use App\Models\Machine;
use App\Models\Procedure;
use App\Services\Definitions\IssuedCodeLock;
use App\Services\Definitions\MachineRetirement;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection as BaseCollection;

/**
 * Makineler (R-12, R-43, K-16, K-18). Makine silinmez; kullanımdan kaldırılır ve geçmiş
 * kayıtlarda görünmeye devam eder. Kullanım durumu kararını MachineRetirement verir.
 */
class MachineController extends Controller
{
    private const PER_PAGE = 25;

    private const STATUSES = ['active' => 'Kullanımda', 'retired' => 'Kullanımdan kaldırıldı'];

    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        $machines = Machine::query()
            ->join('lines', 'lines.id', '=', 'machines.line_id')
            ->join('facilities', 'facilities.id', '=', 'lines.facility_id')
            ->select('machines.*')
            ->addSelect(['open_cleanings_count' => Cleaning::query()
                ->selectRaw('count(*)')
                ->whereColumn('cleanings.machine_id', 'machines.id')
                ->whereIn('status', [CleaningStatus::Created, CleaningStatus::InProgress])])
            ->when($filters['line_id'], fn (Builder $query, int $lineId) => $query->where('machines.line_id', $lineId))
            ->when($filters['status'], fn (Builder $query, string $status) => $query->where('machines.is_active', $status === 'active'))
            ->with(['line.facility', 'procedure' => $this->withCurrentVersion(...)])
            ->orderBy('facilities.code')
            ->orderBy('lines.code')
            ->orderBy('machines.code')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.machines.index', [
            'machines' => $machines,
            'filters' => $filters,
            'isFiltered' => $filters['line_id'] !== null || $filters['status'] !== null,
            'statuses' => self::STATUSES,
            'lineGroups' => $this->lineGroups(),
        ]);
    }

    public function create(Request $request): View
    {
        $lineId = filter_var($request->query('line_id'), FILTER_VALIDATE_INT);

        return view('admin.machines.form', [
            'machine' => new Machine(['line_id' => $lineId === false ? null : $lineId]),
            'codeLocked' => false,
            'lineGroups' => $this->lineGroups(),
            'procedures' => $this->procedureOptions(),
        ]);
    }

    public function store(MachineRequest $request): RedirectResponse
    {
        // Yeni makine kullanımda başlar; prosedürü zorunludur (K-18).
        $machine = Machine::create([...$request->validated(), 'is_active' => true]);

        return redirect()
            ->route('admin.machines.show', $machine)
            ->with('status', "{$machine->code} makinesi eklendi.");
    }

    public function show(Machine $machine, MachineRetirement $retirement): View
    {
        $machine->load('line.facility', 'procedure');

        return view('admin.machines.show', [
            'machine' => $machine,
            'currentVersion' => $machine->procedure?->currentVersion(),
            'openCleanings' => $retirement->blockingCleanings($machine),
            'cleaningCount' => Cleaning::query()->where('machine_id', $machine->id)->count(),
        ]);
    }

    public function edit(Machine $machine, IssuedCodeLock $locks): View
    {
        $machine->load('line.facility');

        return view('admin.machines.form', [
            'machine' => $machine,
            'codeLocked' => $locks->machineLocked($machine),
            'lineGroups' => $this->lineGroups(),
            'procedures' => $this->procedureOptions($machine),
        ]);
    }

    public function update(MachineRequest $request, Machine $machine): RedirectResponse
    {
        $machine->update($request->validated());

        return redirect()
            ->route('admin.machines.show', $machine)
            ->with('status', "{$machine->code} makinesi güncellendi.");
    }

    public function retire(Machine $machine, MachineRetirement $retirement): RedirectResponse
    {
        $retirement->retire($machine);

        return redirect()
            ->route('admin.machines.show', $machine)
            ->with('status', "{$machine->code} makinesi kullanımdan kaldırıldı. Yeni kayıtlarda seçilemez; geçmiş kayıtlarda görünmeye devam eder.");
    }

    public function reinstate(Machine $machine, MachineRetirement $retirement): RedirectResponse
    {
        $retirement->reinstate($machine);

        return redirect()
            ->route('admin.machines.show', $machine)
            ->with('status', "{$machine->code} makinesi yeniden kullanıma alındı.");
    }

    /**
     * Geçersiz filtre değerleri yok sayılır.
     *
     * @return array{line_id: ?int, status: ?string}
     */
    private function filters(Request $request): array
    {
        $lineId = filter_var($request->query('line_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $status = $request->query('status');

        return [
            'line_id' => $lineId === false ? null : $lineId,
            'status' => is_string($status) && array_key_exists($status, self::STATUSES) ? $status : null,
        ];
    }

    /**
     * <optgroup> için tesise göre gruplanmış hatlar.
     *
     * @return BaseCollection<string, Collection<int, Line>>
     */
    private function lineGroups(): BaseCollection
    {
        return Line::query()
            ->join('facilities', 'facilities.id', '=', 'lines.facility_id')
            ->select('lines.*')
            ->orderBy('facilities.code')
            ->orderBy('lines.code')
            ->with('facility')
            ->get()
            ->groupBy(fn (Line $line) => "{$line->facility->code} — {$line->facility->name}");
    }

    /**
     * K-18: yalnızca yayımlanmış versiyonu olan prosedürler seçilebilir. Düzenlenen makinenin
     * mevcut prosedürü bu koşulu sağlamasa da listede kalır; form onu sessizce değiştirmez.
     *
     * @return Collection<int, Procedure>
     */
    private function procedureOptions(?Machine $machine = null): Collection
    {
        return Procedure::query()
            ->where(fn (Builder $query) => $query
                ->whereHas('versions', $this->published(...))
                ->when($machine?->procedure_id, fn (Builder $query, int $id) => $query->orWhereKey($id)))
            ->withMax(['versions as current_version' => $this->published(...)], 'version')
            ->orderBy('code')
            ->get();
    }

    /**
     * Yeni kayıtlara uygulanacak versiyonun numarası: `current_version` (Procedure::currentVersion ile aynı koşul).
     */
    private function withCurrentVersion(Builder|Relation $query): void
    {
        $query->withMax(['versions as current_version' => $this->published(...)], 'version');
    }

    private function published(Builder $query): void
    {
        $query->where('published_at', '<=', now());
    }
}

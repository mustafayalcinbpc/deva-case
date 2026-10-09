<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Catalog\SaveWorkOrderRequest;
use App\Models\Line;
use App\Models\Machine;
use App\Models\WorkOrder;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Validation\ValidationException;

/**
 * Üretim iş emirleri (K-19). Demoda elle girilir, gerçekte ERP'den gelir. İş emri bir hatta, bir
 * makineye ya da hiçbirine bağlıdır. Kayıtlarda kullanılan iş emrinin kodu ve bağlantısı
 * değişmez; yalnızca açıklaması düzeltilebilir.
 */
class WorkOrderController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        $workOrders = WorkOrder::query()
            ->with(['machine.line.facility', 'line.facility'])
            ->withCount('cleanings')
            ->when($filters['q'], fn (Builder $query, string $term) => $query->where(fn (Builder $query) => $query
                ->whereLike('code', '%'.$this->escapeLike($term).'%')
                ->orWhereLike('description', '%'.$this->escapeLike($term).'%')))
            // Hatta göre: hatta bağlı iş emirleri ve o hattın makinelerine bağlı olanlar.
            ->when($filters['line_id'], fn (Builder $query, int $lineId) => $query->where(fn (Builder $query) => $query
                ->where('line_id', $lineId)
                ->orWhereIn('machine_id', Machine::query()->select('id')->where('line_id', $lineId))))
            ->when($filters['machine_id'], fn (Builder $query, int $machineId) => $query->where('machine_id', $machineId))
            ->orderBy('code')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.work-orders.index', [
            'workOrders' => $workOrders,
            'filters' => $filters,
            'isFiltered' => $filters['q'] !== null || $filters['line_id'] !== null || $filters['machine_id'] !== null,
            'lineGroups' => $this->lineGroups(),
            'machineGroups' => $this->machineGroups(),
        ]);
    }

    public function create(): View
    {
        return $this->form(new WorkOrder, 0);
    }

    public function store(SaveWorkOrderRequest $request): RedirectResponse
    {
        $workOrder = WorkOrder::create($request->attributesToSave());

        return redirect()
            ->route('admin.work-orders.index')
            ->with('status', "İş emri eklendi: {$workOrder->code}");
    }

    public function edit(WorkOrder $workOrder): View
    {
        return $this->form($workOrder, $workOrder->cleanings()->count());
    }

    public function update(SaveWorkOrderRequest $request, WorkOrder $workOrder): RedirectResponse
    {
        // Eski verilerde makineye bağlı iş emrinin hattı boş olabilir; bağlantı, makinenin hattıyla karşılaştırılır.
        $binding = [$workOrder->machine_id, $workOrder->line_id ?? $workOrder->machine?->line_id];

        $workOrder->fill($request->attributesToSave());

        // Kayıt, açıldığı andaki iş emrine bağlıdır; kullanılmış iş emrinin kimliği değişmez.
        $codeChanged = $workOrder->isDirty('code');
        $bindingChanged = $binding !== [$workOrder->machine_id, $workOrder->line_id];

        if (($codeChanged || $bindingChanged) && $workOrder->cleanings()->exists()) {
            throw ValidationException::withMessages([
                $codeChanged ? 'code' : 'machine_id' => 'Bu iş emri kayıtlarda kullanıldığı için kodu ve bağlantısı değiştirilemez; yalnızca açıklaması düzeltilebilir.',
            ]);
        }

        $workOrder->save();

        return redirect()
            ->route('admin.work-orders.index')
            ->with('status', "İş emri güncellendi: {$workOrder->code}");
    }

    private function form(WorkOrder $workOrder, int $usage): View
    {
        return view('admin.work-orders.form', [
            'workOrder' => $workOrder->loadMissing(['machine.line.facility', 'line.facility']),
            'usage' => $usage,
            'lineGroups' => $this->lineGroups(),
            'machineGroups' => $this->machineGroups(),
        ]);
    }

    /**
     * Geçersiz filtre değerleri yok sayılır.
     *
     * @return array{q: ?string, line_id: ?int, machine_id: ?int}
     */
    private function filters(Request $request): array
    {
        $id = fn (string $key) => filter_var($request->query($key), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $term = $request->query('q');

        return [
            'q' => is_string($term) && trim($term) !== '' ? trim($term) : null,
            'line_id' => $id('line_id'),
            'machine_id' => $id('machine_id'),
        ];
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * <optgroup> için tesis adına göre gruplanmış hatlar.
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
            ->groupBy(fn (Line $line) => $line->facility->name);
    }

    /**
     * <optgroup> için "Tesis / Hat" başlığına göre gruplanmış makineler. Kullanımdan kaldırılmış
     * makineler de listelenir (mevcut bağlantı korunabilsin diye); seçenekte belirtilir.
     *
     * @return BaseCollection<string, Collection<int, Machine>>
     */
    private function machineGroups(): BaseCollection
    {
        return Machine::query()
            ->join('lines', 'lines.id', '=', 'machines.line_id')
            ->join('facilities', 'facilities.id', '=', 'lines.facility_id')
            ->select('machines.*')
            ->orderBy('facilities.code')
            ->orderBy('lines.code')
            ->orderBy('machines.code')
            ->with('line.facility')
            ->get()
            ->groupBy(fn (Machine $machine) => "{$machine->line->facility->name} / {$machine->line->name}");
    }
}

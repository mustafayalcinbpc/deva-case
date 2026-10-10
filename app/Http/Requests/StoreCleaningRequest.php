<?php

namespace App\Http\Requests;

use App\Enums\CleaningType;
use App\Models\CleaningTask;
use App\Models\Machine;
use App\Models\MaterialLot;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Cleaning\MaterialEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Kayıt açma formu (R-14–R-20). Burada yalnızca girdinin biçimi doğrulanır; iş kuralları
 * (makine kullanımda mı, prosedürü var mı, üretim iş emri makineye ait mi, lot kullanılabilir mi,
 * yardımcının aktifliği, görev açık mı) CleaningWorkflow::open() içindedir.
 *
 * Malzeme satırı: materials[i][material_lot_id] seçilen lot (K-14); materials[i][material_id]
 * yalnızca formun satırı yeniden çizmesi içindir (prosedürden gelen satır), kurala girmez.
 * Lotu seçilmemiş satır yok sayılır: zorunlu malzeme ilk adımdan önce de girilebilir (K-12).
 */
class StoreCleaningRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'machine_id' => ['required', 'integer', Rule::exists(Machine::class, 'id')],
            'type' => ['required', Rule::enum(CleaningType::class)],
            'helper_ids' => ['nullable', 'array'],
            'helper_ids.*' => ['integer', Rule::exists(User::class, 'id')],
            'work_order_id' => ['nullable', 'integer', Rule::exists(WorkOrder::class, 'id')],
            'notes' => ['nullable', 'string', 'max:2000'],
            'cleaning_task_id' => ['nullable', 'integer', Rule::exists(CleaningTask::class, 'id')],
            'materials' => ['nullable', 'array'],
            'materials.*.material_id' => ['nullable', 'integer'],
            'materials.*.material_lot_id' => ['required', 'integer', Rule::exists(MaterialLot::class, 'id')],
        ];
    }

    /**
     * Lotu seçilmemiş malzeme satırları yok sayılır. Satır anahtarları korunur, böylece hata
     * mesajları formdaki doğru satıra düşer.
     */
    protected function prepareForValidation(): void
    {
        $materials = $this->input('materials');

        if (is_array($materials)) {
            $this->merge(['materials' => array_filter($materials, fn (mixed $row) => ! $this->isBlankMaterialRow($row))]);
        }
    }

    public function machine(): Machine
    {
        return Machine::query()->findOrFail($this->validated('machine_id'));
    }

    public function cleaningType(): CleaningType
    {
        return CleaningType::from($this->validated('type'));
    }

    /**
     * @return list<int>
     */
    public function helperIds(): array
    {
        return array_map(intval(...), array_values($this->validated('helper_ids') ?? []));
    }

    /**
     * Kaydın açıldığı görev (K-21); görevsiz kayıtta null.
     */
    public function task(): ?CleaningTask
    {
        $id = $this->validated('cleaning_task_id');

        return $id === null ? null : CleaningTask::query()->findOrFail($id);
    }

    public function workOrder(): ?WorkOrder
    {
        $id = $this->validated('work_order_id');

        return $id === null ? null : WorkOrder::query()->findOrFail($id);
    }

    /**
     * @return list<MaterialEntry>
     */
    public function materialEntries(): array
    {
        return array_map(
            fn (array $row) => new MaterialEntry((int) $row['material_lot_id']),
            array_values($this->validated('materials') ?? []),
        );
    }

    public function notes(): ?string
    {
        return $this->validated('notes');
    }

    private function isBlankMaterialRow(mixed $row): bool
    {
        return is_array($row) && blank($row['material_lot_id'] ?? null);
    }
}

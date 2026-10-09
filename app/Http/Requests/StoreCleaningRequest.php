<?php

namespace App\Http\Requests;

use App\Enums\CleaningType;
use App\Models\Machine;
use App\Models\Material;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Cleaning\MaterialEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Kayıt açma formu (R-14–R-20). Burada yalnızca girdinin biçimi doğrulanır; iş kuralları
 * (makine kullanımda mı, prosedürü var mı, iş emri makineye ait mi, malzemenin son kullanma
 * tarihi, yardımcının aktifliği) CleaningWorkflow::open() içindedir.
 */
class StoreCleaningRequest extends FormRequest
{
    private const MATERIAL_FIELDS = ['material_id', 'lot_no', 'expiry_date'];

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
            'materials' => ['nullable', 'array'],
            'materials.*.material_id' => ['required', 'integer', Rule::exists(Material::class, 'id')],
            'materials.*.lot_no' => ['required', 'string', 'max:255'],
            'materials.*.expiry_date' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /**
     * Formda her zaman boş bir malzeme satırı bulunur; üç alanı da boş satırlar yok sayılır.
     * Satır anahtarları korunur, böylece hata mesajları formdaki doğru satıra düşer.
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
            fn (array $row) => new MaterialEntry((int) $row['material_id'], $row['lot_no'], $row['expiry_date']),
            array_values($this->validated('materials') ?? []),
        );
    }

    public function notes(): ?string
    {
        return $this->validated('notes');
    }

    private function isBlankMaterialRow(mixed $row): bool
    {
        if (! is_array($row)) {
            return false;
        }

        foreach (self::MATERIAL_FIELDS as $field) {
            if (filled($row[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }
}

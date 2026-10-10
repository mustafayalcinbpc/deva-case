<?php

namespace App\Http\Requests\Admin\Catalog;

use App\Models\Line;
use App\Models\Machine;
use App\Models\WorkOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Üretim iş emri: ekleme ve düzenleme (K-19). Üretim iş emri bir hatta, bir makineye ya da hiçbirine bağlıdır.
 * Makineye bağlı üretim iş emri o makinenin hattına da bağlıdır: hat boş bırakılırsa makineden gelir,
 * ikisi birlikte seçilirse makine o hatta olmalıdır.
 */
class SaveWorkOrderRequest extends FormRequest
{
    private ?Machine $machine = null;

    public function authorize(): bool
    {
        return $this->user()->can('manage-definitions');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50', Rule::unique(WorkOrder::class, 'code')->ignore($this->route('work_order'))],
            'description' => ['nullable', 'string', 'max:255'],
            'line_id' => ['nullable', 'integer', Rule::exists(Line::class, 'id')],
            'machine_id' => ['nullable', 'integer', Rule::exists(Machine::class, 'id')],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->hasAny(['line_id', 'machine_id'])) {
                    return;
                }

                $machine = $this->machine();
                $lineId = $this->input('line_id');

                if ($machine !== null && filled($lineId) && $machine->line_id !== (int) $lineId) {
                    $validator->errors()->add('machine_id', 'Seçilen makine, seçilen hatta değil.');
                }
            },
        ];
    }

    /**
     * Kaydedilecek alanlar; makineye bağlı üretim iş emrinin hattı makineden gelir.
     *
     * @return array{code: string, description: ?string, line_id: ?int, machine_id: ?int}
     */
    public function attributesToSave(): array
    {
        $machine = $this->machine();
        $lineId = $this->validated('line_id');

        return [
            'code' => $this->validated('code'),
            'description' => $this->validated('description'),
            'line_id' => $machine?->line_id ?? ($lineId === null ? null : (int) $lineId),
            'machine_id' => $machine?->id,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'üretim iş emri kodu',
            'description' => 'açıklama',
            'line_id' => 'hat',
            'machine_id' => 'makine',
        ];
    }

    private function machine(): ?Machine
    {
        $id = $this->input('machine_id');

        if (! filled($id)) {
            return null;
        }

        return $this->machine ??= Machine::query()->find((int) $id);
    }
}

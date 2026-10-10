<?php

namespace App\Http\Requests\Admin\Catalog;

use App\Models\Line;
use App\Models\Machine;
use App\Models\WorkOrder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Üretim iş emri: ekleme ve düzenleme (K-19). Üretim iş emri bir hatta, bir makineye ya da hiçbirine bağlıdır.
 * Makineye bağlı üretim iş emri o makinenin hattına da bağlıdır: hat boş bırakılırsa makineden gelir,
 * ikisi birlikte seçilirse makine o hatta olmalıdır.
 *
 * Ürün ve planlanan zamanlar bilgi içindir (gerçekte ERP'den gelir). Zamanlar gösterim saat
 * diliminde (config('app.display_timezone')) girilir, UTC saklanır. Durum bu formla değişmez;
 * "Üretime al" ve "Tamamlandı" WorkOrderLifecycle üzerinden yapılır (K-20 tetiği).
 */
class SaveWorkOrderRequest extends FormRequest
{
    public const INPUT_FORMAT = 'Y-m-d\TH:i';

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
            'product' => ['nullable', 'string', 'max:255'],
            'planned_start_at' => ['nullable', 'date_format:'.self::INPUT_FORMAT],
            'planned_end_at' => ['nullable', 'date_format:'.self::INPUT_FORMAT, 'after_or_equal:planned_start_at'],
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
     * @return array{code: string, description: ?string, product: ?string, planned_start_at: ?CarbonImmutable, planned_end_at: ?CarbonImmutable, line_id: ?int, machine_id: ?int}
     */
    public function attributesToSave(): array
    {
        $machine = $this->machine();
        $lineId = $this->validated('line_id');

        return [
            'code' => $this->validated('code'),
            'description' => $this->validated('description'),
            'product' => $this->validated('product'),
            'planned_start_at' => $this->moment('planned_start_at'),
            'planned_end_at' => $this->moment('planned_end_at'),
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
            'product' => 'ürün',
            'planned_start_at' => 'planlanan başlangıç',
            'planned_end_at' => 'planlanan bitiş',
            'line_id' => 'hat',
            'machine_id' => 'makine',
        ];
    }

    private function moment(string $key): ?CarbonImmutable
    {
        $value = $this->validated($key);

        return $value === null
            ? null
            : CarbonImmutable::createFromFormat(self::INPUT_FORMAT, $value, config('app.display_timezone'))->startOfMinute()->utc();
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

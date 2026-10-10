<?php

namespace App\Http\Requests\Admin\Planning;

use App\Enums\CleaningPlanKind;
use App\Models\CleaningPlan;
use App\Models\Machine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Temizlik planı: ekleme ve düzenleme (K-20). Periyodik planda aralık (gün) zorunludur; "üretim
 * iş emri tamamlanınca" kuralında aralık yoktur. Bir makinede aynı kurallı tek kullanımdaki plan
 * olur. Yeni plan yalnızca kullanımdaki makineye eklenir.
 */
class SaveCleaningPlanRequest extends FormRequest
{
    public const MAX_INTERVAL_DAYS = 365;

    public function authorize(): bool
    {
        return $this->user()->can('manage-definitions');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $plan = $this->route('cleaning_plan');

        return [
            // Düzenlemede mevcut (belki kullanımdan kaldırılmış) makine korunabilir.
            'machine_id' => ['required', 'integer', Rule::exists(Machine::class, 'id')->where(fn ($query) => $query
                ->where('is_active', true)
                ->when($plan instanceof CleaningPlan, fn ($query) => $query->orWhere('id', $plan->machine_id)))],
            'kind' => ['required', Rule::enum(CleaningPlanKind::class)],
            'interval_days' => [
                'nullable',
                'required_if:kind,'.CleaningPlanKind::Periodic->value,
                'integer',
                'min:1',
                'max:'.self::MAX_INTERVAL_DAYS,
            ],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->hasAny(['machine_id', 'kind'])) {
                    return;
                }

                $plan = $this->route('cleaning_plan');
                $duplicate = CleaningPlan::query()
                    ->active()
                    ->where('machine_id', $this->integer('machine_id'))
                    ->where('kind', $this->input('kind'))
                    ->when($plan instanceof CleaningPlan, fn ($query) => $query->whereKeyNot($plan->id))
                    ->exists();

                if ($duplicate) {
                    $validator->errors()->add('machine_id', 'Bu makinede aynı kurallı, kullanımda bir temizlik planı var.');
                }
            },
        ];
    }

    /**
     * @return array{machine_id: int, kind: CleaningPlanKind, interval_days: ?int}
     */
    public function attributesToSave(): array
    {
        $kind = CleaningPlanKind::from($this->validated('kind'));

        return [
            'machine_id' => (int) $this->validated('machine_id'),
            'kind' => $kind,
            'interval_days' => $kind === CleaningPlanKind::Periodic ? (int) $this->validated('interval_days') : null,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'machine_id' => 'makine',
            'kind' => 'kural',
            'interval_days' => 'aralık (gün)',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'interval_days.required_if' => 'Periyodik planda aralık (gün) zorunludur.',
        ];
    }
}

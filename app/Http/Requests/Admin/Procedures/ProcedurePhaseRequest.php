<?php

namespace App\Http\Requests\Admin\Procedures;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Faz ekleme ve düzenleme: ad, dakika cinsinden minimum süre (R-06) ve adımlar arası
 * boşlukların faz süresine dahil olup olmadığı (K-02). Süre saniye olarak saklanır.
 */
class ProcedurePhaseRequest extends FormRequest
{
    /** Bir fazın minimum süresi en fazla 24 saat. */
    public const MAX_MINUTES = 1440;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'min_duration_minutes' => ['required', 'integer', 'min:0', 'max:'.self::MAX_MINUTES],
            'include_gaps' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array{name: string, min_duration_seconds: int, include_gaps: bool}
     */
    public function phaseAttributes(): array
    {
        return [
            'name' => $this->validated('name'),
            'min_duration_seconds' => (int) $this->validated('min_duration_minutes') * 60,
            'include_gaps' => $this->boolean('include_gaps'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'faz adı',
            'min_duration_minutes' => 'minimum süre',
            'include_gaps' => 'süre ölçümü',
        ];
    }
}

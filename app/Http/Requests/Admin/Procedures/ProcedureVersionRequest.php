<?php

namespace App\Http\Requests\Admin\Procedures;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Taslak versiyonun genel ayarı: malzeme zorunluluğu (K-13). İşaretlenmemiş kutu "hayır" demektir.
 */
class ProcedureVersionRequest extends FormRequest
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
            'material_required' => ['nullable', 'boolean'],
        ];
    }

    public function materialRequired(): bool
    {
        return $this->boolean('material_required');
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'material_required' => 'malzeme zorunluluğu',
        ];
    }
}

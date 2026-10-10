<?php

namespace App\Http\Requests\Admin\Procedures;

use App\Models\Material;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Taslağın beklediği malzeme (K-12, K-13): eklerken malzeme ve zorunluluk, düzenlerken yalnızca
 * zorunluluk. İşaretlenmemiş "zorunlu" kutusu "isteğe bağlı" demektir. Listede tekrar ve
 * kullanımdan kaldırılmış malzeme kuralı ProcedureVersioning'dedir.
 */
class ProcedureVersionMaterialRequest extends FormRequest
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
        $rules = ['is_required' => ['nullable', 'boolean']];

        if ($this->isMethod('post')) {
            $rules['material_id'] = ['required', 'integer', Rule::exists(Material::class, 'id')->where('is_active', true)];
        }

        return $rules;
    }

    public function material(): Material
    {
        return Material::query()->findOrFail($this->validated('material_id'));
    }

    public function isRequired(): bool
    {
        return $this->boolean('is_required');
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'material_id' => 'malzeme',
            'is_required' => 'zorunluluk',
        ];
    }
}

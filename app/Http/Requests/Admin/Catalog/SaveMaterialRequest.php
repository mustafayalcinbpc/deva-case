<?php

namespace App\Http\Requests\Admin\Catalog;

use App\Models\Material;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Malzeme kataloğu: ekleme ve düzenleme (K-13). Kayıtlarda kullanılan malzemenin kodunun
 * değişmemesi kuralı controller'dadır.
 */
class SaveMaterialRequest extends FormRequest
{
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
            'code' => ['required', 'string', 'max:50', Rule::unique(Material::class, 'code')->ignore($this->route('material'))],
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'malzeme kodu',
            'name' => 'malzeme adı',
        ];
    }
}

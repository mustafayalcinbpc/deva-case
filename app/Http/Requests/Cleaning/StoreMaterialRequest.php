<?php

namespace App\Http\Requests\Cleaning;

use App\Models\Material;
use App\Services\Cleaning\MaterialEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Kayda malzeme ekleme (K-12, K-13). Son kullanma tarihinin geçip geçmediğini sunucu tarihine
 * göre workflow denetler (K-14).
 */
class StoreMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Yetkiyi workflow denetler.
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'material_id' => ['required', 'integer', Rule::exists(Material::class, 'id')],
            'lot_no' => ['required', 'string', 'max:100'],
            'expiry_date' => ['required', 'date_format:Y-m-d'],
        ];
    }

    public function entry(): MaterialEntry
    {
        return new MaterialEntry(
            materialId: (int) $this->validated('material_id'),
            lotNo: $this->validated('lot_no'),
            expiryDate: $this->validated('expiry_date'),
        );
    }
}

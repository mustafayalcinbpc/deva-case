<?php

namespace App\Http\Requests\Admin\Catalog;

use App\Models\MaterialLot;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Malzeme lotu: ekleme ve düzenleme (K-14). Lot no malzeme içinde tekildir. Kayıtlarda
 * kullanılan lotun numarasının değişmemesi kuralı controller'dadır; SKT düzeltilebilir
 * (kayıtlar seçildiği andaki kopyayı taşır).
 */
class SaveMaterialLotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-definitions');
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('lot_no'))) {
            $this->merge(['lot_no' => trim($this->input('lot_no'))]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $material = $this->route('material');

        return [
            'lot_no' => [
                'required', 'string', 'max:100',
                Rule::unique(MaterialLot::class, 'lot_no')->where('material_id', $material->id)->ignore($this->route('lot')),
            ],
            'expiry_date' => ['required', 'date_format:Y-m-d'],
            'received_at' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'lot_no' => 'lot numarası',
            'expiry_date' => 'son kullanma tarihi',
            'received_at' => 'giriş tarihi',
        ];
    }
}

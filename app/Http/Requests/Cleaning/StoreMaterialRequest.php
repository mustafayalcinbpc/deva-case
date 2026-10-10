<?php

namespace App\Http\Requests\Cleaning;

use App\Models\MaterialLot;
use App\Services\Cleaning\MaterialEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Kayda malzeme ekleme (K-12, K-14): operatör lotu seçer; lot no ve SKT lot kaydından gelir.
 * Lotun kullanımda olup olmadığını ve SKT'sinin geçip geçmediğini workflow denetler.
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
            'material_lot_id' => ['required', 'integer', Rule::exists(MaterialLot::class, 'id')],
        ];
    }

    public function entry(): MaterialEntry
    {
        return new MaterialEntry((int) $this->validated('material_lot_id'));
    }

    /**
     * Doğrulama hatasında kayıt detayı Malzemeler sekmesinde açılır; hata gizli sekmede kalmaz.
     */
    protected function getRedirectUrl(): string
    {
        return strtok(parent::getRedirectUrl(), '#').'#materials';
    }
}

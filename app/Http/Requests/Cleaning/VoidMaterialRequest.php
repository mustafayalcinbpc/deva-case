<?php

namespace App\Http\Requests\Cleaning;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Yanlış girilen malzemeyi gerekçeyle geçersiz kılma (K-12).
 */
class VoidMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Yetkiyi workflow denetler.
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'void_reason' => ['required', 'string', 'max:1000'],
        ];
    }

    public function reason(): string
    {
        return $this->validated('void_reason');
    }

    /**
     * Doğrulama hatasında kayıt detayı Malzemeler sekmesinde açılır; hata gizli sekmede kalmaz.
     */
    protected function getRedirectUrl(): string
    {
        return strtok(parent::getRedirectUrl(), '#').'#materials';
    }
}

<?php

namespace App\Http\Requests\Cleaning;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Adımı tamamlama. Gerekçe yalnızca faz minimum sürenin altında kapanıyorsa gerekir (K-01);
 * gerekip gerekmediğine workflow karar verir, burada yalnızca biçimi doğrulanır.
 */
class CompleteStepRequest extends FormRequest
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
            'deviation_reason' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function deviationReason(): ?string
    {
        return $this->validated('deviation_reason');
    }
}

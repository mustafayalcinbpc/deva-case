<?php

namespace App\Http\Requests\Cleaning;

use App\Enums\CancelReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Kaydı iptal etme (K-08, K-09). Kimin hangi gerekçeyle iptal edebileceğini workflow denetler;
 * burada yalnızca gerekçenin geçerli bir değer olduğu doğrulanır.
 */
class CancelCleaningRequest extends FormRequest
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
            'cancel_reason' => ['required', Rule::enum(CancelReason::class)],
            'cancel_note' => ['required', 'string', 'max:2000'],
        ];
    }

    public function reason(): CancelReason
    {
        return CancelReason::from($this->validated('cancel_reason'));
    }

    public function note(): string
    {
        return $this->validated('cancel_note');
    }
}

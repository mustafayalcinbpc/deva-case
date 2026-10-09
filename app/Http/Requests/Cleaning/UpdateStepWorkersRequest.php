<?php

namespace App\Http\Requests\Cleaning;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adımın görevli listesi (R-22, R-23). Kişilerin aktif olup olmadığını ve listenin kurallara
 * uyup uymadığını workflow denetler; burada yalnızca biçim ve varlık doğrulanır.
 */
class UpdateStepWorkersRequest extends FormRequest
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
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'distinct', Rule::exists(User::class, 'id')],
        ];
    }

    /**
     * @return list<int>
     */
    public function userIds(): array
    {
        return array_map(intval(...), array_values($this->validated('user_ids')));
    }
}

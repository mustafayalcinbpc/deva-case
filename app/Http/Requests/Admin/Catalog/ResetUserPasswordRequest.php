<?php

namespace App\Http\Requests\Admin\Catalog;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Yöneticinin bir kullanıcıya yeni şifre vermesi.
 */
class ResetUserPasswordRequest extends FormRequest
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
            'password' => ['required', 'string', 'min:'.StoreUserRequest::PASSWORD_MIN, 'max:255', 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'password' => 'yeni şifre',
        ];
    }
}

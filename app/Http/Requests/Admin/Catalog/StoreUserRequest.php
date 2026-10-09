<?php

namespace App\Http\Requests\Admin\Catalog;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Yeni kullanıcı (R-40). Şifre en az 4 karakterdir; demo hesapları 1234 kullanır.
 */
class StoreUserRequest extends FormRequest
{
    public const PASSWORD_MIN = 4;

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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'role' => ['required', Rule::enum(UserRole::class)],
            'password' => ['required', 'string', 'min:'.self::PASSWORD_MIN, 'max:255', 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'ad soyad',
            'email' => 'e-posta',
            'role' => 'rol',
            'password' => 'şifre',
        ];
    }
}

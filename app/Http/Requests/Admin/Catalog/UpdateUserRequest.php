<?php

namespace App\Http\Requests\Admin\Catalog;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Kullanıcı bilgileri: ad, e-posta, rol. Yöneticinin kendi rolünü kaldıramaması kuralı
 * controller'dadır.
 */
class UpdateUserRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')->ignore($this->route('user'))],
            'role' => ['required', Rule::enum(UserRole::class)],
        ];
    }

    public function role(): UserRole
    {
        return UserRole::from($this->validated('role'));
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
        ];
    }
}

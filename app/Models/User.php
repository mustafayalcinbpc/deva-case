<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\DefinitionChangeAction;
use App\Enums\UserRole;
use App\Models\Concerns\RecordsDefinitionChanges;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, RecordsDefinitionChanges;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
        ];
    }

    public function isManager(): bool
    {
        return $this->role === UserRole::Manager;
    }

    public function definitionChangeLabel(): string
    {
        return (string) $this->email;
    }

    /**
     * Pasife alma (R-36) ve şifre sıfırlama; şifrenin kendisi günlüğe yazılmaz.
     */
    protected function definitionChangeActions(): array
    {
        return [
            'is_active' => fn ($old, $new) => $new ? DefinitionChangeAction::Activated : DefinitionChangeAction::Deactivated,
            'password' => fn () => DefinitionChangeAction::PasswordReset,
        ];
    }
}

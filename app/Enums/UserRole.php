<?php

namespace App\Enums;

enum UserRole: string
{
    case Operator = 'operator';
    case Manager = 'manager';

    public function label(): string
    {
        return match ($this) {
            self::Operator => 'Operatör',
            self::Manager => 'Yönetici',
        };
    }
}

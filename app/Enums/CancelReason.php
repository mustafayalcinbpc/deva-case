<?php

namespace App\Enums;

enum CancelReason: string
{
    case InvalidRecord = 'invalid_record';
    case PersonnelLeft = 'personnel_left';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::InvalidRecord => 'Hatalı kayıt',
            self::PersonnelLeft => 'Personel ayrıldı',
            self::Other => 'Diğer',
        };
    }
}

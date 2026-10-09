<?php

namespace App\Enums;

/**
 * Tanım değişiklik günlüğündeki işlem (R-49). Genel işlemlerin (oluşturma, güncelleme, silme)
 * yanında alan değişikliğinden çıkarılan iş işlemleri: makineyi kullanımdan kaldırma (K-16),
 * versiyon yayımlama (K-15), malzeme ve kullanıcıyı pasife alma (R-36), şifre sıfırlama.
 */
enum DefinitionChangeAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Retired = 'retired';
    case Reinstated = 'reinstated';
    case Published = 'published';
    case Deactivated = 'deactivated';
    case Activated = 'activated';
    case PasswordReset = 'password_reset';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Oluşturuldu',
            self::Updated => 'Güncellendi',
            self::Deleted => 'Silindi',
            self::Retired => 'Kullanımdan kaldırıldı',
            self::Reinstated => 'Yeniden kullanıma alındı',
            self::Published => 'Yayımlandı',
            self::Deactivated => 'Pasife alındı',
            self::Activated => 'Aktif edildi',
            self::PasswordReset => 'Şifre sıfırlandı',
        };
    }
}

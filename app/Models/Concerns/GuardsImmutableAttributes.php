<?php

namespace App\Models\Concerns;

use LogicException;

/**
 * Bir kez dolan alanların sonradan değiştirilmesini ve kaydın silinmesini engeller
 * (R-47, R-48). Alanlar NULL iken bir kez doldurulabilir; doldurulduktan sonra sabittir.
 */
trait GuardsImmutableAttributes
{
    /**
     * @return list<string>
     */
    abstract protected function immutableAttributes(): array;

    public static function bootGuardsImmutableAttributes(): void
    {
        static::updating(function (self $model) {
            foreach ($model->immutableAttributes() as $attribute) {
                if ($model->isDirty($attribute) && $model->getOriginal($attribute) !== null) {
                    throw new LogicException(class_basename($model)." kaydının '{$attribute}' alanı değiştirilemez.");
                }
            }
        });

        static::deleting(function (self $model) {
            throw new LogicException(class_basename($model).' kayıtları silinemez.');
        });
    }
}

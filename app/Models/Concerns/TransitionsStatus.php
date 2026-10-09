<?php

namespace App\Models\Concerns;

use App\Exceptions\CleaningRuleViolation;
use BackedEnum;

/**
 * `status` alanı DefinesTransitions kullanan bir enum'a cast edilmiş modeller için.
 * Durum yalnızca enum'da tanımlı geçişlerle değiştirilir.
 */
trait TransitionsStatus
{
    public function transitionTo(BackedEnum $target): void
    {
        if (! $this->status->canTransitionTo($target)) {
            throw CleaningRuleViolation::invalidTransition(class_basename($this), $this->status, $target);
        }

        $this->status = $target;
    }
}

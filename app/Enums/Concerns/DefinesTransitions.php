<?php

namespace App\Enums\Concerns;

trait DefinesTransitions
{
    /**
     * Bu durumdan geçilebilecek durumlar.
     *
     * @return list<self>
     */
    abstract public function transitions(): array;

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->transitions(), true);
    }
}

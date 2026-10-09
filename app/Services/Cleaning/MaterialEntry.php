<?php

namespace App\Services\Cleaning;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Operatörün girdiği bir malzeme satırı: katalogdaki malzeme, lot ve son kullanma tarihi (K-13).
 */
final readonly class MaterialEntry
{
    public function __construct(
        public int $materialId,
        public string $lotNo,
        public string $expiryDate, // Y-m-d
    ) {}

    /**
     * K-14: son kullanma tarihi verilen günden önceyse malzeme kullanılamaz; o gün geçerlidir.
     */
    public function isExpiredOn(CarbonInterface $day): bool
    {
        return CarbonImmutable::parse($this->expiryDate)->toDateString() < $day->toDateString();
    }
}

<?php

namespace App\Services\Cleaning;

/**
 * Operatörün seçtiği malzeme lotu (K-14). Malzeme, lot no ve SKT lot kaydından gelir; operatör
 * bunları elle girmez. Lotun kullanılabilirliğine workflow karar verir.
 */
final readonly class MaterialEntry
{
    public function __construct(
        public int $materialLotId,
    ) {}
}

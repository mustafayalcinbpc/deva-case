<?php

namespace App\Services\Definitions;

use App\Models\Cleaning;
use App\Models\Facility;
use App\Models\Line;
use App\Models\Machine;

/**
 * K-17: tesis, hat ve makine kodları verilmiş kayıt numaralarının ve saha defteri
 * referanslarının parçasıdır. Bir tanım için kayıt açıldıktan sonra kodu (makinede hattı da)
 * değiştirilemez; aksi halde aynı yerdeki kayıtlar farklı numaralarla görünür.
 */
final class IssuedCodeLock
{
    public const FACILITY_REASON = 'Bu tesiste temizlik kaydı açılmış. Tesis kodu kayıt numaralarında ve saha defteri referanslarında kullanıldığı için değiştirilemez (K-17).';

    public const LINE_REASON = 'Bu hatta temizlik kaydı açılmış. Hat kodu kayıt numaralarında kullanıldığı için değiştirilemez (K-17).';

    public const MACHINE_REASON = 'Bu makinede temizlik kaydı açılmış. Makine kodu ve hattı kayıt numaralarında kullanıldığı için değiştirilemez (K-17).';

    public function facilityLocked(Facility $facility): bool
    {
        return $facility->exists && Cleaning::query()->where('facility_id', $facility->id)->exists();
    }

    public function lineLocked(Line $line): bool
    {
        return $line->exists && Cleaning::query()->where('line_id', $line->id)->exists();
    }

    public function machineLocked(Machine $machine): bool
    {
        return $machine->exists && Cleaning::query()->where('machine_id', $machine->id)->exists();
    }
}

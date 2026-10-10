<?php

namespace App\Services\Cleaning;

use App\Models\Facility;
use App\Models\Machine;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Kayıt numarası ve saha defteri referansı üretimi (R-17, R-18, K-17).
 * Sıra numaraları `sequence_counters` satırı kilitlenerek artırılır; eşzamanlı
 * isteklerde aynı numara iki kez verilmez. Çağıranın transaction'ı içinde kullanılır.
 */
final class RecordNumberGenerator
{
    /**
     * Örn. IST-H01M03-260042: tesis, hat+makine, yılın son iki hanesi ve dört haneli sıra.
     * Sıra makine ve yıl bazındadır; planlı ve plansız kayıtlar aynı sırayı paylaşır
     * (tür numarada yer almaz, kayıtta ayrıca durur).
     */
    public function recordNo(Machine $machine, CarbonInterface $at): string
    {
        $machine->loadMissing('line.facility');

        $year = $this->localYear($at);
        $sequence = $this->next("cleaning:{$machine->id}:{$year}");

        return sprintf(
            '%s-%s%s-%s',
            $machine->line->facility->code,
            $machine->line->code,
            $machine->code,
            $this->yearAndSequence($year, $sequence),
        );
    }

    /**
     * Örn. IST-SD-260123. Sıra tesis ve yıl bazındadır.
     */
    public function fieldRef(Facility $facility, CarbonInterface $at): string
    {
        $year = $this->localYear($at);
        $sequence = $this->next("field-ref:{$facility->id}:{$year}");

        return sprintf('%s-SD-%s', $facility->code, $this->yearAndSequence($year, $sequence));
    }

    /**
     * "26" + "0042". Yıl her zaman iki hanedir; sıra 9999'u aşarsa beş haneye uzar ve numara
     * yine tekildir (sayaç anahtarı tam yılı tutar).
     */
    private function yearAndSequence(int $year, int $sequence): string
    {
        return sprintf('%02d%04d', $year % 100, $sequence);
    }

    /**
     * Yıl, tesisin yerel takvimine göre (gösterim saat dilimi) belirlenir; zaman UTC saklansa da
     * 1 Ocak 00:00–03:00 arasında açılan kayıt yeni yılın numarasını alır.
     */
    private function localYear(CarbonInterface $at): int
    {
        return $at->copy()->setTimezone(config('app.display_timezone'))->year;
    }

    private function next(string $key): int
    {
        // Dışarıda transaction varsa savepoint olur; yoksa kilit en azından bu blok boyunca tutulur.
        return DB::transaction(function () use ($key) {
            $counter = fn () => DB::table('sequence_counters')->where('key', $key);

            // Satır varken INSERT IGNORE yapılmaz: yinelenen anahtarda paylaşımlı kilit alır ve
            // aynı anda gelen iki istek FOR UPDATE'e yükseltirken birbirini bekler (deadlock).
            if (! $counter()->exists()) {
                DB::table('sequence_counters')->insertOrIgnore(['key' => $key, 'value' => 0]);
            }

            $value = (int) $counter()->lockForUpdate()->value('value') + 1;

            $counter()->update(['value' => $value]);

            return $value;
        });
    }
}

<?php

namespace App\Services\Cleaning;

use App\Models\WorkSlice;
use Carbon\CarbonInterface;

/**
 * Çalışma dilimlerinden süre ve efor hesabı (R-26, R-28, K-02, K-04).
 * Açık dilimler şu ana kadar geçen süreyle sayılır.
 */
final class WorkTime
{
    /**
     * Net süre: dilim sürelerinin toplamı; dilimler arasındaki boşluklar sayılmaz.
     *
     * @param  iterable<WorkSlice>  $slices
     */
    public static function net(iterable $slices): int
    {
        $total = 0;

        foreach ($slices as $slice) {
            $total += $slice->durationSeconds();
        }

        return $total;
    }

    /**
     * Brüt süre: ilk dilimin başlangıcından son dilimin bitişine kadar geçen süre.
     *
     * @param  iterable<WorkSlice>  $slices
     */
    public static function gross(iterable $slices): int
    {
        /** @var CarbonInterface|null $start */
        $start = null;
        /** @var CarbonInterface|null $end */
        $end = null;

        foreach ($slices as $slice) {
            if ($start === null || $slice->started_at->lt($start)) {
                $start = $slice->started_at;
            }

            $sliceEnd = $slice->endOrNow();

            if ($end === null || $sliceEnd->gt($end)) {
                $end = $sliceEnd;
            }
        }

        if ($start === null) {
            return 0;
        }

        return max(0, (int) $start->diffInSeconds($end));
    }

    /**
     * İnsan eforu: her dilim için süre × dilimdeki kişi sayısı.
     *
     * @param  iterable<WorkSlice>  $slices
     */
    public static function effort(iterable $slices): int
    {
        $total = 0;

        foreach ($slices as $slice) {
            $total += $slice->durationSeconds() * $slice->workerCount();
        }

        return $total;
    }
}

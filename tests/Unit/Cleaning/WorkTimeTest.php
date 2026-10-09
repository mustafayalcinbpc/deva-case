<?php

namespace Tests\Unit\Cleaning;

use App\Models\WorkSlice;
use App\Services\Cleaning\WorkTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Dilimler bellekte kurulur; veritabanına gidilmez (kişi sayısı `workers_count` ile verilir).
 */
class WorkTimeTest extends TestCase
{
    public function test_r26_ten_minutes_with_two_people_is_600_seconds_duration_and_1200_seconds_effort(): void
    {
        $slices = [$this->slice('10:00:00', '10:10:00', workers: 2)];

        $this->assertSame(600, WorkTime::net($slices));
        $this->assertSame(600, WorkTime::gross($slices));
        $this->assertSame(1200, WorkTime::effort($slices));
    }

    public function test_r28_gap_between_slices_counts_only_in_gross(): void
    {
        // 12 sn çalışma, 40 dk boşluk, 10 dk çalışma.
        $slices = [
            $this->slice('10:00:00', '10:00:12'),
            $this->slice('10:40:12', '10:50:12'),
        ];

        $this->assertSame(612, WorkTime::net($slices));
        $this->assertSame(3012, WorkTime::gross($slices));
        $this->assertSame(612, WorkTime::effort($slices));
    }

    public function test_k04_effort_is_summed_per_slice_with_that_slices_worker_count(): void
    {
        // Adım sürerken bir kişi eklendi: 5 dk × 2 kişi + 10 dk × 3 kişi.
        $slices = collect([
            $this->slice('10:00:00', '10:05:00', workers: 2),
            $this->slice('10:05:00', '10:15:00', workers: 3),
        ]);

        $this->assertSame(900, WorkTime::net($slices));
        $this->assertSame(900, WorkTime::gross($slices));
        $this->assertSame(600 + 1800, WorkTime::effort($slices));
    }

    public function test_empty_set_is_zero(): void
    {
        $this->assertSame(0, WorkTime::net([]));
        $this->assertSame(0, WorkTime::gross([]));
        $this->assertSame(0, WorkTime::effort(new Collection));
    }

    public function test_open_slice_is_measured_until_now(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-09 10:30:00'));

        $slices = [
            $this->slice('09:00:00', '09:10:00'),
            $this->slice('10:00:00', null, workers: 2),
        ];

        $this->assertSame(600 + 1800, WorkTime::net($slices));
        $this->assertSame(5400, WorkTime::gross($slices));
        $this->assertSame(600 + 3600, WorkTime::effort($slices));

        $this->travel(5)->minutes();

        $this->assertSame(600 + 2100, WorkTime::net($slices));
        $this->assertSame(5700, WorkTime::gross($slices));
    }

    public function test_gross_uses_earliest_start_and_latest_end_regardless_of_order(): void
    {
        $slices = [
            $this->slice('11:00:00', '11:05:00'),
            $this->slice('10:00:00', '10:01:00'),
            $this->slice('10:30:00', '10:31:00'),
        ];

        $this->assertSame(420, WorkTime::net($slices));
        $this->assertSame(3900, WorkTime::gross($slices));
    }

    public function test_accepts_any_iterable(): void
    {
        $generator = function () {
            yield $this->slice('10:00:00', '10:01:00', workers: 2);
            yield $this->slice('10:02:00', '10:03:00', workers: 1);
        };

        $this->assertSame(120, WorkTime::net($generator()));
        $this->assertSame(180, WorkTime::gross($generator()));
        $this->assertSame(180, WorkTime::effort($generator()));
    }

    private function slice(string $start, ?string $end, int $workers = 1): WorkSlice
    {
        $slice = new WorkSlice;
        $slice->started_at = CarbonImmutable::parse("2026-10-09 {$start}");
        $slice->ended_at = $end === null ? null : CarbonImmutable::parse("2026-10-09 {$end}");
        $slice->workers_count = $workers;

        return $slice;
    }
}

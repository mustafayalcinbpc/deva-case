<?php

namespace Tests\Feature\Cleaning;

use App\Enums\CancelReason;
use App\Enums\CleaningStatus;
use App\Models\Cleaning;
use App\Models\CleaningEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\TestCase;

/**
 * Kapalı kayıt (completed, expired, cancelled) üzerinde her işlem `record_closed` döner
 * (Sözleşme 1B; R-47, R-48, K-06, K-08).
 */
class ClosedRecordTest extends TestCase
{
    use BuildsCleaningFixtures, InteractsWithCleaningWorkflow, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('08:00:00');
    }

    public static function operationsOnClosedRecords(): iterable
    {
        $operations = ['startStep', 'pauseStep', 'resumeStep', 'completeStep', 'setWorkers', 'addMaterial', 'voidMaterial', 'cancel'];

        foreach ([CleaningStatus::Completed, CleaningStatus::Cancelled, CleaningStatus::Expired] as $status) {
            foreach ($operations as $operation) {
                yield "{$status->value}: {$operation}" => [$status, $operation];
            }
        }
    }

    #[DataProvider('operationsOnClosedRecords')]
    public function test_every_operation_on_a_closed_record_is_rejected(CleaningStatus $status, string $operation): void
    {
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $manager = $this->manager();
        $cleaning = $this->closedCleaning($status, $ahmet, $manager);
        $step = $this->stepOf($cleaning, 1);
        $item = $cleaning->materials()->sole();
        $events = CleaningEvent::count();
        $this->travel(1)->minutes();

        $this->assertRuleViolation('record_closed', fn () => match ($operation) {
            'setWorkers' => $this->workflow()->setWorkers($ahmet, $step, $this->ids($ahmet, $mehmet)),
            'addMaterial' => $this->workflow()->addMaterial($ahmet, $cleaning, $this->entry($this->makeMaterial('EXTRA-01'), 'LOT-X')),
            'voidMaterial' => $this->workflow()->voidMaterial($ahmet, $item, 'Yanlış lot'),
            'cancel' => $this->workflow()->cancel($manager, $cleaning, CancelReason::Other, 'Tekrar kapatma denemesi'),
            default => $this->workflow()->{$operation}($ahmet, $step),
        });

        $this->assertSame($status, $cleaning->fresh()->status);
        $this->assertSame($events, CleaningEvent::count());
        $this->assertNull($item->fresh()->voided_at);
        $this->assertSame(1, $cleaning->materials()->count());
    }

    public function test_step_left_paused_by_a_cancellation_cannot_be_resumed(): void
    {
        // K-08: yarım kalan iş devralınamaz; tamamlanması gerekiyorsa yeni kayıt açılır.
        $machine = $this->makeMachine([['steps' => 2]]);
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine);
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $this->travel(5)->minutes();
        $this->workflow()->cancel($this->manager(), $cleaning->fresh(), CancelReason::PersonnelLeft, 'Ahmet ayrıldı');

        $this->assertRuleViolation('record_closed', fn () => $this->workflow()->resumeStep($ahmet, $this->stepOf($cleaning, 1)));

        $this->assertSame(1, $this->stepOf($cleaning, 1)->slices()->count());
    }

    /**
     * Tek adımlı, bir malzemeli, Ahmet'in açtığı bir kaydı istenen kapalı duruma getirir.
     */
    private function closedCleaning(CleaningStatus $status, User $owner, User $manager): Cleaning
    {
        $machine = $this->makeMachine([['steps' => 1]]);
        $cleaning = $this->openCleaning($owner, $machine, materials: [$this->entry($this->makeMaterial('DET-01'))]);

        if ($status === CleaningStatus::Completed) {
            $this->runStep($owner, $cleaning, 1);
        } elseif ($status === CleaningStatus::Cancelled) {
            $this->workflow()->cancel($manager, $cleaning->fresh(), CancelReason::Other, 'Yönetici kapattı');
        } else {
            $this->travel(31)->minutes();
            $this->workflow()->expireStale();
        }

        $cleaning = $cleaning->fresh();
        $this->assertSame($status, $cleaning->status);

        return $cleaning;
    }
}

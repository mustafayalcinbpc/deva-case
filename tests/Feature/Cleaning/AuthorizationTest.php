<?php

namespace Tests\Feature\Cleaning;

use App\Enums\CleaningStatus;
use App\Enums\StepStatus;
use App\Models\Cleaning;
use App\Models\CleaningMaterial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\TestCase;

/**
 * Görmek ≠ çalıştırmak (R-44, K-10, K-11): adım işlemleri yalnızca kaydın sahibine ve
 * o adımın aktif görevlisine; malzeme işlemleri sahibine ve herhangi bir adımın aktif
 * görevlisine açıktır. Yönetici rolü adım/malzeme işlemlerinde ayrıcalık vermez.
 */
class AuthorizationTest extends TestCase
{
    use BuildsCleaningFixtures, InteractsWithCleaningWorkflow, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('08:00:00');
    }

    public static function stepOperationsByOutsiders(): iterable
    {
        foreach (['operator', 'manager'] as $outsider) {
            yield "{$outsider}: startStep" => [$outsider, 'startStep', StepStatus::Pending];
            yield "{$outsider}: pauseStep" => [$outsider, 'pauseStep', StepStatus::Running];
            yield "{$outsider}: resumeStep" => [$outsider, 'resumeStep', StepStatus::Paused];
            yield "{$outsider}: completeStep" => [$outsider, 'completeStep', StepStatus::Running];
            yield "{$outsider}: setWorkers" => [$outsider, 'setWorkers', StepStatus::Running];
        }
    }

    #[DataProvider('stepOperationsByOutsiders')]
    public function test_people_who_are_neither_owner_nor_assignee_cannot_operate_a_step(string $outsider, string $operation, StepStatus $state): void
    {
        // R-44: Mehmet, Ahmet'in kaydını görebilir ama adımını başlatamaz/kapatamaz.
        // K-11: yönetici de bu işlemlerde ayrıcalıklı değildir.
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $other = $outsider === 'manager' ? $this->manager('Yönetici') : $this->operator('Mehmet');
        $cleaning = $this->openCleaning($ahmet, $machine);
        $this->bringFirstStepTo($state, $ahmet, $cleaning);
        $events = $this->eventTypes($cleaning);

        $this->assertRuleViolation('not_allowed', fn () => match ($operation) {
            'setWorkers' => $this->workflow()->setWorkers($other, $this->stepOf($cleaning, 1), $this->ids($ahmet, $other)),
            default => $this->workflow()->{$operation}($other, $this->stepOf($cleaning, 1)),
        });

        $this->assertSame($state, $this->stepOf($cleaning, 1)->status);
        $this->assertSame([$ahmet->id], $this->activeAssigneeIds($this->stepOf($cleaning, 1)));
        $this->assertSame($events, $this->eventTypes($cleaning));
    }

    public function test_helper_assigned_to_the_step_can_run_it_end_to_end(): void
    {
        // R-44, K-11: o adımda görevli olan kişi adımı ilerletebilir.
        $machine = $this->makeMachine([['steps' => 1]]);
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $ayse = $this->operator('Ayşe');
        $cleaning = $this->openCleaning($ahmet, $machine, [$mehmet]);

        $this->workflow()->startStep($mehmet, $this->stepOf($cleaning, 1));
        $this->travel(1)->minutes();
        $this->workflow()->setWorkers($mehmet, $this->stepOf($cleaning, 1), $this->ids($ahmet, $mehmet, $ayse));
        $this->travel(1)->minutes();
        $this->workflow()->pauseStep($mehmet, $this->stepOf($cleaning, 1));
        $this->workflow()->resumeStep($mehmet, $this->stepOf($cleaning, 1));
        $this->travel(1)->minutes();
        $this->workflow()->completeStep($mehmet, $this->stepOf($cleaning, 1));

        $this->assertSame(CleaningStatus::Completed, $cleaning->fresh()->status);
        $this->assertEquals($mehmet->id, $this->lastEvent($cleaning, 'step.completed')->actor_id);
        $this->assertEquals($mehmet->id, $this->stepOf($cleaning, 1)->slices()->get()[0]->started_by);
    }

    public function test_helper_removed_from_a_step_can_no_longer_operate_it(): void
    {
        // K-10, R-44: yetki adım bazındadır; 2. adımdan çıkarılan Mehmet onu başlatamaz.
        $machine = $this->makeMachine([['steps' => 2]]);
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $cleaning = $this->openCleaning($ahmet, $machine, [$mehmet]);
        $this->workflow()->setWorkers($ahmet, $this->stepOf($cleaning, 2), [$ahmet->id]);
        $this->runStep($mehmet, $cleaning, 1);

        $this->assertRuleViolation('not_allowed', fn () => $this->workflow()->startStep($mehmet, $this->stepOf($cleaning, 2)));

        $this->assertSame(StepStatus::Pending, $this->stepOf($cleaning, 2)->status);
    }

    public function test_helper_removed_from_a_step_cannot_add_themselves_back(): void
    {
        $machine = $this->makeMachine([['steps' => 2]]);
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $cleaning = $this->openCleaning($ahmet, $machine, [$mehmet]);
        $this->workflow()->setWorkers($ahmet, $this->stepOf($cleaning, 2), [$ahmet->id]);

        $this->assertRuleViolation('not_allowed', fn () => $this->workflow()->setWorkers($mehmet, $this->stepOf($cleaning, 2), $this->ids($ahmet, $mehmet)));

        $this->assertSame([$ahmet->id], $this->activeAssigneeIds($this->stepOf($cleaning, 2)));
    }

    public function test_owner_removed_from_a_step_can_still_operate_it(): void
    {
        // K-10: sahibi görevli listesinden çıkarılsa da başlatma/duraklatma/kapatma yetkisi sürer.
        $machine = $this->makeMachine([['steps' => 1]]);
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $cleaning = $this->openCleaning($ahmet, $machine, [$mehmet]);
        $this->workflow()->setWorkers($ahmet, $this->stepOf($cleaning, 1), [$mehmet->id]);

        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $this->travel(2)->minutes();
        $this->workflow()->pauseStep($ahmet, $this->stepOf($cleaning, 1));
        $this->workflow()->resumeStep($ahmet, $this->stepOf($cleaning, 1));
        $this->travel(2)->minutes();
        $this->workflow()->completeStep($ahmet, $this->stepOf($cleaning, 1));

        $this->assertSame(CleaningStatus::Completed, $cleaning->fresh()->status);
        foreach ($this->stepOf($cleaning, 1)->slices()->get() as $slice) {
            $this->assertSame([$mehmet->id], $this->sliceWorkerIds($slice));
            $this->assertEquals($ahmet->id, $slice->started_by);
        }
    }

    public function test_inactive_owner_cannot_operate_steps(): void
    {
        // Sözleşme 1B: işlemi yapan aktif değilse inactive_user (R-36: işten ayrılan kişi).
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine);
        $this->deactivate($ahmet);

        $this->assertRuleViolation('inactive_user', fn () => $this->workflow()->startStep($ahmet->fresh(), $this->stepOf($cleaning, 1)));

        $this->assertSame(CleaningStatus::Created, $cleaning->fresh()->status);
    }

    public static function materialOutsiders(): iterable
    {
        yield 'kayıtla ilgisi olmayan operatör' => ['operator'];
        yield 'yönetici' => ['manager'];
    }

    #[DataProvider('materialOutsiders')]
    public function test_outsiders_cannot_add_materials(string $outsider): void
    {
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $other = $outsider === 'manager' ? $this->manager() : $this->operator('Mehmet');
        $cleaning = $this->openCleaning($ahmet, $machine);

        $this->assertRuleViolation('not_allowed', fn () => $this->workflow()->addMaterial($other, $cleaning->fresh(), $this->entry($this->makeMaterial())));

        $this->assertSame(0, CleaningMaterial::count());
    }

    #[DataProvider('materialOutsiders')]
    public function test_outsiders_cannot_void_materials(string $outsider): void
    {
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $other = $outsider === 'manager' ? $this->manager() : $this->operator('Mehmet');
        $cleaning = $this->openCleaning($ahmet, $machine, materials: [$this->entry($this->makeMaterial())]);
        $item = $cleaning->materials()->sole();

        $this->assertRuleViolation('not_allowed', fn () => $this->workflow()->voidMaterial($other, $item, 'Yanlış lot'));

        $this->assertNull($item->fresh()->voided_at);
    }

    public function test_assignee_of_any_step_can_add_and_void_materials(): void
    {
        // Sözleşme 1B: malzeme işlemleri kaydın herhangi bir adımının aktif görevlisine açık.
        // Mehmet yalnızca 2. adımda görevli.
        $machine = $this->makeMachine([['steps' => 2]]);
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $cleaning = $this->openCleaning($ahmet, $machine, [$mehmet]);
        $this->workflow()->setWorkers($ahmet, $this->stepOf($cleaning, 1), [$ahmet->id]);

        $item = $this->workflow()->addMaterial($mehmet, $cleaning->fresh(), $this->entry($this->makeMaterial(), 'LOT-M'));
        $this->workflow()->voidMaterial($mehmet, $item, 'Lot numarası yanlış okundu');

        $item = $item->fresh();
        $this->assertEquals($mehmet->id, $item->added_by);
        $this->assertEquals($mehmet->id, $item->voided_by);
        $this->assertNotNull($item->voided_at);
    }

    /**
     * Tek görevlisi sahibi olan 1. adımı istenen duruma getirir.
     */
    private function bringFirstStepTo(StepStatus $state, User $owner, Cleaning $cleaning): void
    {
        if ($state === StepStatus::Pending) {
            return;
        }

        $this->workflow()->startStep($owner, $this->stepOf($cleaning, 1));
        $this->travel(1)->minutes();

        if ($state === StepStatus::Paused) {
            $this->workflow()->pauseStep($owner, $this->stepOf($cleaning, 1));
        }
    }
}

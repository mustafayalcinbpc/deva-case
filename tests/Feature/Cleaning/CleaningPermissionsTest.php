<?php

namespace Tests\Feature\Cleaning;

use App\Enums\CancelReason;
use App\Services\Cleaning\CleaningPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\TestCase;

/**
 * Ekranların buton gösterirken kullandığı yetki kuralları (workflow da aynı sınıfı kullanır).
 */
class CleaningPermissionsTest extends TestCase
{
    use BuildsCleaningFixtures, InteractsWithCleaningWorkflow, RefreshDatabase;

    public function test_step_can_be_operated_by_owner_and_its_assignees_only(): void
    {
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $ayse = $this->operator('Ayşe');
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine(), helpers: [$mehmet]);
        $step = $this->stepOf($cleaning, 1);
        $permissions = app(CleaningPermissions::class);

        $this->assertTrue($permissions->canOperateStep($ahmet, $cleaning, $step));
        $this->assertTrue($permissions->canOperateStep($mehmet, $cleaning, $step));
        $this->assertFalse($permissions->canOperateStep($ayse, $cleaning, $step));
        $this->assertFalse($permissions->canOperateStep($this->manager(), $cleaning, $step));

        $this->assertTrue($permissions->canManageMaterials($mehmet, $cleaning));
        $this->assertFalse($permissions->canManageMaterials($ayse, $cleaning));
    }

    public function test_cancel_reasons_by_role_and_status(): void
    {
        $ahmet = $this->operator('Ahmet');
        $manager = $this->manager();
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine());
        $permissions = app(CleaningPermissions::class);

        $this->assertSame([CancelReason::InvalidRecord], $permissions->allowedCancelReasons($ahmet, $cleaning));
        $this->assertSame(CancelReason::cases(), $permissions->allowedCancelReasons($manager, $cleaning));
        $this->assertSame([], $permissions->allowedCancelReasons($this->operator('Ayşe'), $cleaning));

        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $cleaning->refresh();
        $this->assertSame([], $permissions->allowedCancelReasons($ahmet, $cleaning));
        $this->assertSame(CancelReason::cases(), $permissions->allowedCancelReasons($manager, $cleaning));

        $this->workflow()->cancel($manager, $cleaning, CancelReason::Other, 'Deneme');
        $this->assertSame([], $permissions->allowedCancelReasons($manager, $cleaning->refresh()));
    }
}

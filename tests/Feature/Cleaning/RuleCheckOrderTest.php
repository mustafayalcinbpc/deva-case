<?php

namespace Tests\Feature\Cleaning;

use App\Enums\CancelReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\TestCase;

/**
 * Sözleşme 1B kontrol sırası: aynı anda birden fazla ihlal varsa ilk olan döner.
 *   record_closed → inactive_user → not_allowed → durum geçişi / step_completed →
 *   step_out_of_order → no_workers → material_required → machine_busy → worker_busy
 * Bu sınıftaki her test bilerek iki ihlal kurar; yalnızca öndeki kural beklenir.
 */
class RuleCheckOrderTest extends TestCase
{
    use BuildsCleaningFixtures, InteractsWithCleaningWorkflow, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('08:00:00');
    }

    public function test_record_closed_comes_before_not_allowed(): void
    {
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $stranger = $this->operator('Mehmet');
        $cleaning = $this->openCleaning($ahmet, $machine);
        $this->workflow()->cancel($ahmet, $cleaning->fresh(), CancelReason::InvalidRecord, 'Hatalı kayıt');

        $this->assertRuleViolation('record_closed', fn () => $this->workflow()->startStep($stranger, $this->stepOf($cleaning, 1)));
    }

    public function test_record_closed_comes_before_inactive_user(): void
    {
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine);
        $this->workflow()->cancel($ahmet, $cleaning->fresh(), CancelReason::InvalidRecord, 'Hatalı kayıt');
        $this->deactivate($ahmet);

        $this->assertRuleViolation('record_closed', fn () => $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1)));
    }

    public function test_inactive_user_comes_before_not_allowed(): void
    {
        $machine = $this->makeMachine();
        $cleaning = $this->openCleaning($this->operator('Ahmet'), $machine);
        $inactiveStranger = $this->deactivate($this->operator('Mehmet'));

        $this->assertRuleViolation('inactive_user', fn () => $this->workflow()->startStep($inactiveStranger, $this->stepOf($cleaning, 1)));
    }

    public function test_not_allowed_comes_before_invalid_transition(): void
    {
        $machine = $this->makeMachine();
        $cleaning = $this->openCleaning($this->operator('Ahmet'), $machine);
        $stranger = $this->operator('Mehmet');

        // Adım pending: hem pause geçişi geçersiz hem de kişi yetkisiz.
        $this->assertRuleViolation('not_allowed', fn () => $this->workflow()->pauseStep($stranger, $this->stepOf($cleaning, 1)));
    }

    public function test_not_allowed_comes_before_step_out_of_order(): void
    {
        $machine = $this->makeMachine([['steps' => 2]]);
        $cleaning = $this->openCleaning($this->operator('Ahmet'), $machine);
        $stranger = $this->operator('Mehmet');

        $this->assertRuleViolation('not_allowed', fn () => $this->workflow()->startStep($stranger, $this->stepOf($cleaning, 2)));
    }

    public function test_step_completed_comes_before_no_workers(): void
    {
        // Genel sıraya göre (setWorkers maddesindeki yazım sırası farklı; bkz. rapor).
        $machine = $this->makeMachine([['steps' => 2]]);
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine);
        $this->runStep($ahmet, $cleaning, 1);

        $this->assertRuleViolation('step_completed', fn () => $this->workflow()->setWorkers($ahmet, $this->stepOf($cleaning, 1), []));
    }

    public function test_step_out_of_order_comes_before_no_workers(): void
    {
        $machine = $this->makeMachine([['steps' => 2]]);
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine);
        $this->removeAllAssigneesDirectly($this->stepOf($cleaning, 2), $ahmet);

        $this->assertRuleViolation('step_out_of_order', fn () => $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 2)));
    }

    public function test_no_workers_comes_before_material_required(): void
    {
        $machine = $this->makeMachine(materialRequired: true);
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine);
        $this->removeAllAssigneesDirectly($this->stepOf($cleaning, 1), $ahmet);

        $this->assertRuleViolation('no_workers', fn () => $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1)));
    }

    public function test_material_required_comes_before_machine_busy(): void
    {
        $machine = $this->makeMachine(materialRequired: true);
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $running = $this->openCleaning($mehmet, $machine, materials: [$this->entry($this->makeMaterial())]);
        $this->workflow()->startStep($mehmet, $this->stepOf($running, 1));
        $withoutMaterial = $this->openCleaning($ahmet, $machine);

        $this->assertRuleViolation('material_required', fn () => $this->workflow()->startStep($ahmet, $this->stepOf($withoutMaterial, 1)));
    }

    public function test_machine_busy_comes_before_worker_busy(): void
    {
        // Ahmet M01'de çalışıyor; M02'de Mehmet'in kaydı devam ediyor; Ahmet M02'de kendi kaydını başlatmaya çalışır.
        $m01 = $this->makeMachine(code: 'M01');
        $m02 = $this->makeMachine(code: 'M02');
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $ahmetsBusy = $this->openCleaning($ahmet, $m01);
        $this->workflow()->startStep($ahmet, $this->stepOf($ahmetsBusy, 1));
        $mehmets = $this->openCleaning($mehmet, $m02);
        $this->workflow()->startStep($mehmet, $this->stepOf($mehmets, 1));
        $ahmetsSecond = $this->openCleaning($ahmet, $m02);

        $e = $this->assertRuleViolation('machine_busy', fn () => $this->workflow()->startStep($ahmet, $this->stepOf($ahmetsSecond, 1)));

        $this->assertEquals($mehmets->id, $e->context['blocking_cleaning_id']);
    }
}

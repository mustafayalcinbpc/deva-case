<?php

namespace Tests\Feature\Admin\Locations;

use App\Enums\CancelReason;
use App\Models\Cleaning;
use App\Models\Machine;
use App\Services\Definitions\MachineRetirement;
use Illuminate\Validation\ValidationException;

/**
 * R-12, K-16, K-18: açık (başlamamış ya da devam eden) kaydı olan makine kullanımdan
 * kaldırılamaz; kaldırılan makine silinmez, yeni kayıtta seçilemez, geçmişte görünür.
 * Yeniden kullanıma almak için yayımlanmış prosedür gerekir.
 */
class MachineRetirementTest extends LocationsTestCase
{
    public function test_machine_without_open_records_is_retired_and_kept(): void
    {
        $machine = $this->makeMachine(code: 'M01');

        $this->actingAs($this->manager)->from(route('admin.machines.show', $machine))
            ->post(route('admin.machines.retire', $machine))
            ->assertRedirect(route('admin.machines.show', $machine))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'M01 makinesi kullanımdan kaldırıldı. Yeni kayıtlarda seçilemez; geçmiş kayıtlarda görünmeye devam eder.');

        $machine->refresh();
        $this->assertFalse($machine->is_active);
        $this->assertNotNull($machine->procedure_id);

        $this->actingAs($this->manager)->get(route('admin.machines.show', $machine))
            ->assertOk()
            ->assertSee('Kullanımdan kaldırıldı')
            ->assertSee('Açılamaz: makine kullanımdan kaldırılmış.')
            ->assertSee(route('admin.machines.reinstate', $machine), false);
    }

    public function test_retire_is_blocked_by_a_record_that_has_not_started(): void
    {
        $machine = $this->makeMachine(code: 'M01');
        $cleaning = $this->openCleaning($this->operator(), $machine);

        $this->assertRetireBlocked($machine, [$cleaning]);
    }

    public function test_retire_is_blocked_by_a_record_in_progress_and_allowed_after_it_completes(): void
    {
        $machine = $this->makeMachine(code: 'M01');
        $owner = $this->operator();
        $cleaning = $this->openCleaning($owner, $machine);
        $this->workflow()->startStep($owner, $this->stepOf($cleaning, 1));

        $this->assertRetireBlocked($machine, [$cleaning]);

        $this->completeRemainingSteps($owner, $cleaning);

        $this->actingAs($this->manager)->post(route('admin.machines.retire', $machine))->assertSessionHasNoErrors();
        $this->assertFalse($machine->fresh()->is_active);
    }

    public function test_retire_lists_every_blocking_record_and_is_allowed_after_they_are_cancelled(): void
    {
        $machine = $this->makeMachine(code: 'M01');
        $owner = $this->operator();
        $first = $this->openCleaning($owner, $machine);
        $second = $this->openCleaning($owner, $machine);

        $this->actingAs($this->manager)->get(route('admin.machines.show', $machine))
            ->assertOk()
            ->assertSeeInOrder(['Bu makinede 2 açık kayıt var', "({$first->record_no}, {$second->record_no})"]);

        $this->assertRetireBlocked($machine, [$first, $second]);

        $this->workflow()->cancel($this->manager, $first, CancelReason::InvalidRecord, 'Yanlış makine');
        $this->assertRetireBlocked($machine, [$second]);

        $this->workflow()->cancel($this->manager, $second, CancelReason::InvalidRecord, 'Yanlış makine');
        $this->actingAs($this->manager)->post(route('admin.machines.retire', $machine))->assertSessionHasNoErrors();
        $this->assertFalse($machine->fresh()->is_active);
    }

    public function test_retired_machine_is_not_offered_for_new_records_but_stays_in_history(): void
    {
        $machine = $this->makeMachine(code: 'M01');
        $this->makeMachine(code: 'M02');
        $owner = $this->operator();
        $cleaning = $this->openCleaning($owner, $machine);
        $this->completeRemainingSteps($owner, $cleaning);

        $this->actingAs($this->manager)->post(route('admin.machines.retire', $machine))->assertSessionHasNoErrors();

        $this->actingAs($owner)->get(route('cleanings.create'))
            ->assertOk()
            ->assertSee('M02 — Makine M02')
            ->assertDontSee('M01 — Makine M01');

        // Geçmiş kayıt ve makine filtresi yerinde kalır.
        $this->actingAs($owner)->get(route('cleanings.index', ['machine_id' => $machine->id]))
            ->assertOk()
            ->assertSee($cleaning->record_no)
            ->assertSee('M01 — Makine M01 (kullanımdan kaldırıldı)');
        $this->actingAs($owner)->get(route('cleanings.show', $cleaning))->assertOk();

        $this->assertSame(2, Machine::count());
    }

    public function test_retiring_twice_is_rejected(): void
    {
        $machine = $this->makeMachine(code: 'M01');
        $machine->update(['is_active' => false]);

        $this->actingAs($this->manager)->post(route('admin.machines.retire', $machine))
            ->assertSessionHasErrors(['machine' => 'M01 makinesi zaten kullanımdan kaldırılmış.']);
    }

    public function test_reinstate_requires_a_procedure_with_a_published_version(): void
    {
        $machine = $this->makeMachine(code: 'M01');
        $machine->update(['is_active' => false, 'procedure_id' => null]);
        $message = 'M01 makinesi kullanıma alınamaz; yayımlanmış versiyonu olan bir prosedür atanmamış. Önce makineyi düzenleyip prosedür seçin (K-18).';

        $this->actingAs($this->manager)->get(route('admin.machines.show', $machine))
            ->assertOk()
            ->assertSee('Yeniden kullanıma almak için makineye yayımlanmış versiyonu olan bir prosedür atanmalı (K-18).');

        $this->actingAs($this->manager)->from(route('admin.machines.show', $machine))
            ->post(route('admin.machines.reinstate', $machine))
            ->assertRedirect(route('admin.machines.show', $machine))
            ->assertSessionHasErrors(['machine' => $message]);
        $this->assertFalse($machine->fresh()->is_active);

        // Yalnızca taslağı olan prosedür de yetmez.
        $machine->update(['procedure_id' => $this->draftProcedure()->id]);
        $this->actingAs($this->manager)->post(route('admin.machines.reinstate', $machine))
            ->assertSessionHasErrors(['machine' => $message]);
        $this->assertFalse($machine->fresh()->is_active);

        // Kullanımdan kaldırılmış makine prosedürsüz düzenlenebilir; yayımlanmış prosedür atanınca kullanıma alınır.
        $this->actingAs($this->manager)
            ->put(route('admin.machines.update', $machine), ['line_id' => $machine->line_id, 'code' => 'M01', 'name' => 'Makine M01', 'procedure_id' => ''])
            ->assertSessionHasNoErrors();
        $this->assertNull($machine->fresh()->procedure_id);

        $procedure = $this->publishedProcedure();
        $this->actingAs($this->manager)
            ->put(route('admin.machines.update', $machine), ['line_id' => $machine->line_id, 'code' => 'M01', 'name' => 'Makine M01', 'procedure_id' => $procedure->id])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->manager)->post(route('admin.machines.reinstate', $machine))
            ->assertRedirect(route('admin.machines.show', $machine))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'M01 makinesi yeniden kullanıma alındı.');
        $this->assertTrue($machine->fresh()->is_active);

        $this->actingAs($this->operator())->get(route('cleanings.create'))
            ->assertOk()
            ->assertSee('M01 — Makine M01');
    }

    public function test_reinstating_an_active_machine_is_rejected(): void
    {
        $machine = $this->makeMachine(code: 'M01');

        $this->actingAs($this->manager)->post(route('admin.machines.reinstate', $machine))
            ->assertSessionHasErrors(['machine' => 'M01 makinesi zaten kullanımda.']);
    }

    public function test_service_reports_blocking_records_and_leaves_the_machine_active(): void
    {
        $machine = $this->makeMachine(code: 'M01');
        $cleaning = $this->openCleaning($this->operator(), $machine);
        $service = app(MachineRetirement::class);

        $this->assertSame([$cleaning->id], $service->blockingCleanings($machine)->modelKeys());

        try {
            $service->retire($machine);
            $this->fail('Açık kaydı olan makine kullanımdan kaldırılmamalı.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($cleaning->record_no, $e->errors()['machine'][0]);
        }

        $this->assertTrue($machine->fresh()->is_active);
    }

    /**
     * @param  list<Cleaning>  $blocking
     */
    private function assertRetireBlocked(Machine $machine, array $blocking): void
    {
        $numbers = implode(', ', array_map(fn (Cleaning $cleaning) => $cleaning->record_no, $blocking));

        $this->actingAs($this->manager)->from(route('admin.machines.show', $machine))
            ->post(route('admin.machines.retire', $machine))
            ->assertRedirect(route('admin.machines.show', $machine))
            ->assertSessionHasErrors([
                'machine' => "{$machine->code} makinesi kullanımdan kaldırılamaz; açık kaydı var: {$numbers}. Kayıtların tamamlanmasını bekleyin ya da kayıtları iptal edin (K-16).",
            ]);

        $this->assertTrue($machine->fresh()->is_active);

        // Hata makine sayfasında, engelleyen kayıtlarla birlikte görünür.
        $page = $this->actingAs($this->manager)->from(route('admin.machines.show', $machine))
            ->followingRedirects()
            ->post(route('admin.machines.retire', $machine))
            ->assertOk()
            ->assertSee("kullanımdan kaldırılamaz; açık kaydı var: {$numbers}.");

        foreach ($blocking as $cleaning) {
            $page->assertSee(route('cleanings.show', $cleaning), false);
        }
    }
}

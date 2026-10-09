<?php

namespace Tests\Feature\Screens;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\TestCase;

/**
 * Kayıt detayındaki denetim raporu bağlantısı ve PDF düğmesi yalnızca yöneticiye görünür (R-43, R-45).
 */
class CleaningDetailAuditLinkTest extends TestCase
{
    use BuildsCleaningFixtures, InteractsWithCleaningWorkflow, RefreshDatabase;

    public function test_manager_sees_audit_report_link_and_pdf_button(): void
    {
        $cleaning = $this->openCleaning($this->operator('Ahmet'), $this->makeMachine());

        $this->actingAs($this->manager())
            ->get(route('cleanings.show', $cleaning))
            ->assertOk()
            ->assertSee('href="'.route('reports.audit', $cleaning).'"', false)
            ->assertSee('action="'.route('reports.audit.pdf', $cleaning).'"', false);
    }

    public function test_operator_does_not_see_audit_report_actions(): void
    {
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine());

        $this->actingAs($ahmet)
            ->get(route('cleanings.show', $cleaning))
            ->assertOk()
            ->assertDontSee(route('reports.audit', $cleaning))
            ->assertDontSee(route('reports.audit.pdf', $cleaning));
    }
}

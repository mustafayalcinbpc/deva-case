<?php

namespace Tests\Feature\Reports;

use App\Models\ReportExport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\TestCase;

/**
 * R-43, K-11: raporlar yalnızca yöneticiye açıktır. Operatör her rapor adresinde 403 alır,
 * oturumu olmayan giriş sayfasına yönlendirilir.
 */
class ReportAccessTest extends TestCase
{
    use BuildsCleaningFixtures, InteractsWithCleaningWorkflow, RefreshDatabase;

    public function test_operator_gets_403_on_every_report_route(): void
    {
        Queue::fake();
        [$operator, $routes] = $this->scenario();

        foreach ($routes as [$method, $url]) {
            $this->actingAs($operator)->call($method, $url)->assertForbidden();
        }

        $this->assertSame(1, ReportExport::query()->count(), 'Operatör dışa aktarma isteği oluşturamaz.');
        Queue::assertNothingPushed();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        [, $routes] = $this->scenario();

        foreach ($routes as [$method, $url]) {
            $this->call($method, $url)->assertRedirect(route('login'));
        }
    }

    public function test_manager_can_open_every_report_page(): void
    {
        [, $routes] = $this->scenario();
        $manager = $this->manager();

        foreach ($routes as [$method, $url]) {
            if ($method === 'GET' && ! str_contains($url, '/download')) {
                $this->actingAs($manager)->get($url)->assertOk();
            }
        }
    }

    public function test_report_menu_is_shown_to_managers_only(): void
    {
        $this->actingAs($this->manager())->get(route('dashboard'))
            ->assertSee(route('reports.durations'))
            ->assertSee(route('reports.deviations'))
            ->assertSee(route('reports.materials'));

        $this->actingAs($this->operator())->get(route('dashboard'))
            ->assertDontSee(route('reports.durations'));
    }

    /**
     * @return array{0: User, 1: list<array{0: string, 1: string}>}
     */
    private function scenario(): array
    {
        $this->at('08:00:00');
        $operator = $this->operator();
        $cleaning = $this->openCleaning($operator, $this->makeMachine());
        $export = ReportExport::create([
            'user_id' => $this->manager('İsteyen')->id,
            'type' => ReportExport::TYPE_DURATIONS_CSV,
            'parameters' => ['filters' => []],
            'status' => ReportExport::STATUS_PENDING,
        ]);

        return [$operator, [
            ['GET', route('reports.durations')],
            ['POST', route('reports.durations.csv')],
            ['GET', route('reports.deviations')],
            ['GET', route('reports.materials')],
            ['GET', route('reports.audit', $cleaning)],
            ['POST', route('reports.audit.pdf', $cleaning)],
            ['GET', route('reports.exports.download', $export)],
        ]];
    }
}

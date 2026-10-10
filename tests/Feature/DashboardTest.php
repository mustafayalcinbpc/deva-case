<?php

namespace Tests\Feature;

use App\Enums\CancelReason;
use App\Enums\CleaningType;
use App\Models\Cleaning;
use App\Models\User;
use Closure;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\TestCase;

/**
 * Gösterge paneli: özet sayaçlar ve açık kayıtlar tablosu. Herkes her kaydı görür
 * (K-11); sahibi ya da güncel adımın görevlisi olunan satırlar işaretlenir (R-44).
 */
class DashboardTest extends TestCase
{
    use BuildsCleaningFixtures, InteractsWithCleaningWorkflow, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('08:00:00');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_operator_and_manager_see_the_same_read_only_dashboard(): void
    {
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine());

        // K-11: başka bir operatör ve yönetici de kaydı görür, ama kendilerine ait değildir.
        foreach ([$this->operator('Mehmet'), $this->manager('Zeynep')] as $viewer) {
            $response = $this->actingAs($viewer)->get(route('dashboard'))
                ->assertOk()
                ->assertSee('<title>Gösterge Paneli', false)
                ->assertSee('Açık kayıtlar')
                ->assertSee($cleaning->record_no);

            $this->assertFalse($this->isMarkedMine($this->rowOf($response, $cleaning)));
            $this->assertNull($this->page($response)->querySelector('.open-cleanings form, .open-cleanings button'));
        }
    }

    public function test_counters_reflect_created_in_progress_completed_today_and_below_minimum(): void
    {
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');

        // 40 gün önce minimum süre altında kapanan faz: 30 günlük sayaca girmez.
        $this->at('09:00:00', '2026-08-30');
        $old = $this->openCleaning($ahmet, $this->makeMachine([['steps' => 1, 'min_seconds' => 600]], code: 'M01'));
        $this->runStep($ahmet, $old, 1, 60, 'Arıza nedeniyle kısa kesildi');

        // 10 gün önce minimum süre altında kapanan faz: sayılır.
        $this->at('09:00:00', '2026-09-29');
        $recent = $this->openCleaning($ahmet, $this->makeMachine([['steps' => 1, 'min_seconds' => 600]], code: 'M02'));
        $this->runStep($ahmet, $recent, 1, 60, 'Malzeme bitti');

        // Dün 20:00–20:20 UTC = 23:00–23:20 İstanbul: dün tamamlanmış sayılır.
        $this->at('20:00:00', '2026-10-08');
        $yesterday = $this->openCleaning($ahmet, $this->makeMachine(code: 'M03'));
        $this->completeRemainingSteps($ahmet, $yesterday, 600);

        // Dün 21:00–21:10 UTC = bugün 00:00–00:10 İstanbul: bugün tamamlanmış sayılır.
        $this->at('21:00:00', '2026-10-08');
        $today = $this->openCleaning($ahmet, $this->makeMachine(code: 'M04'));
        $this->completeRemainingSteps($ahmet, $today, 300);

        // Süresi dolan (K-06) ve iptal edilen kayıtlar açık sayılmaz.
        $this->at('06:00:00');
        $this->openCleaning($ahmet, $this->makeMachine(code: 'M05'));
        $this->at('07:00:00');
        $this->assertSame(1, $this->workflow()->expireStale());
        $cancelled = $this->openCleaning($ahmet, $this->makeMachine(code: 'M06'));
        $this->workflow()->cancel($ahmet, $cancelled, CancelReason::InvalidRecord, 'Yanlış makine');

        // K-05: kayıt açmak makineyi kilitlemez; aynı makinede iki başlamamış kayıt olabilir.
        $this->at('08:00:00');
        $machine = $this->makeMachine(code: 'M07');
        $this->openCleaning($ahmet, $machine);
        $this->openCleaning($mehmet, $machine);
        $running = $this->openCleaning($ahmet, $this->makeMachine(code: 'M08'));
        $this->workflow()->startStep($ahmet, $this->stepOf($running, 1));

        $this->travel(5)->minutes();
        $response = $this->actingAs($mehmet)->get(route('dashboard'))->assertOk();

        $response->assertViewHas('stats', [
            'created' => 2,
            'in_progress' => 1,
            'completed_today' => 1,
            'below_minimum' => 1,
        ]);
        $this->assertSame('2', $this->statValue($response, 'created'));
        $this->assertSame('1', $this->statValue($response, 'in-progress'));
        $this->assertSame('1', $this->statValue($response, 'completed-today'));
        $this->assertSame('1', $this->statValue($response, 'below-minimum'));
        $response->assertSee('09.10.2026')->assertSee('Son 30 gün');
    }

    public function test_open_records_are_listed_and_closed_ones_are_not(): void
    {
        $ahmet = $this->operator('Ahmet Yılmaz');
        $ayse = $this->operator('Ayşe Demir');

        $completed = $this->openCleaning($ahmet, $this->makeMachine(code: 'M01'));
        $this->completeRemainingSteps($ahmet, $completed);
        $cancelled = $this->openCleaning($ahmet, $this->makeMachine(code: 'M02'));
        $this->workflow()->cancel($ahmet, $cancelled, CancelReason::InvalidRecord, 'Yanlış makine');

        $this->at('08:30:00');
        $created = $this->openCleaning($ayse, $this->makeMachine(code: 'M03'), type: CleaningType::Unplanned);
        $inProgress = $this->openCleaning($ahmet, $this->makeMachine(code: 'M04'));
        $this->at('08:40:00');
        $this->workflow()->startStep($ahmet, $this->stepOf($inProgress, 1));

        $response = $this->actingAs($this->manager())->get(route('dashboard'))->assertOk()
            ->assertDontSee($completed->record_no)
            ->assertDontSee($cancelled->record_no);

        $row = $this->rowText($this->rowOf($response, $created));
        $this->assertStringContainsString('IST / H01 / M03', $row);
        $this->assertStringContainsString('Plansız müdahale', $row);
        $this->assertStringContainsString('Ayşe Demir', $row);
        $this->assertStringContainsString('Başlamadı', $row);
        $this->assertStringContainsString('9 Ekim 11:30', $row, 'Açılış zamanı İstanbul saatiyle gösterilmeli.');

        $row = $this->rowText($this->rowOf($response, $inProgress));
        $this->assertStringContainsString('IST / H01 / M04', $row);
        $this->assertStringContainsString('Planlı temizlik', $row);
        $this->assertStringContainsString('Ahmet Yılmaz', $row);
        $this->assertStringContainsString('Devam ediyor', $row);
        $this->assertStringContainsString('9 Ekim 11:40', $row, 'Başlangıç zamanı gösterilmeli.');
    }

    public function test_records_with_the_newest_activity_come_first(): void
    {
        $ahmet = $this->operator();
        $first = $this->openCleaning($ahmet, $this->makeMachine(code: 'M01'));
        $this->at('08:05:00');
        $second = $this->openCleaning($ahmet, $this->makeMachine(code: 'M02'));
        $viewer = $this->operator('İzleyici');

        $this->actingAs($viewer)->get(route('dashboard'))
            ->assertSeeInOrder([$second->record_no, $first->record_no]);

        // İlk kayıtta adım başlatıldı: son hareket onda.
        $this->at('08:10:00');
        $this->workflow()->startStep($ahmet, $this->stepOf($first, 1));

        $this->actingAs($viewer)->get(route('dashboard'))
            ->assertSeeInOrder([$first->record_no, $second->record_no]);
    }

    public function test_current_step_is_the_running_paused_or_next_pending_step(): void
    {
        $ahmet = $this->operator();
        $notStarted = $this->openCleaning($ahmet, $this->makeMachine(code: 'M01'));

        $betweenSteps = $this->openCleaning($ahmet, $this->makeMachine(code: 'M02'));
        $this->runStep($ahmet, $betweenSteps, 1);

        $paused = $this->openCleaning($ahmet, $this->makeMachine(code: 'M03'));
        $this->workflow()->startStep($ahmet, $this->stepOf($paused, 1));
        $this->workflow()->pauseStep($ahmet, $this->stepOf($paused, 1));

        $running = $this->openCleaning($ahmet, $this->makeMachine([['steps' => 1], ['steps' => 2]], code: 'M04'));
        $this->runStep($ahmet, $running, 1);
        $this->workflow()->startStep($ahmet, $this->stepOf($running, 2));

        $response = $this->actingAs($ahmet)->get(route('dashboard'))->assertOk();

        $this->assertSame('Faz 1 adım 1 Bekliyor', $this->currentStepText($response, $notStarted));
        $this->assertSame('Faz 1 adım 2 Bekliyor', $this->currentStepText($response, $betweenSteps));
        $this->assertSame('Faz 1 adım 1 Duraklatıldı', $this->currentStepText($response, $paused));
        $this->assertSame('Faz 2 adım 1 Çalışıyor', $this->currentStepText($response, $running));
    }

    public function test_net_working_time_excludes_pauses_and_is_empty_before_start(): void
    {
        $ahmet = $this->operator();
        $notStarted = $this->openCleaning($ahmet, $this->makeMachine(code: 'M01'));
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine(code: 'M02'));

        // 08:00–08:10 çalıştı, 08:10–08:30 duraklatıldı, 08:30'dan beri çalışıyor.
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $this->at('08:10:00');
        $this->workflow()->pauseStep($ahmet, $this->stepOf($cleaning, 1));
        $this->at('08:30:00');
        $this->workflow()->resumeStep($ahmet, $this->stepOf($cleaning, 1));
        $this->at('08:35:00');

        $response = $this->actingAs($ahmet)->get(route('dashboard'))->assertOk();

        $this->assertStringEndsWith('15 dk 00 sn', $this->rowText($this->rowOf($response, $cleaning)));
        $this->assertStringEndsWith('—', $this->rowText($this->rowOf($response, $notStarted)));
    }

    public function test_rows_are_marked_for_the_owner_and_current_step_assignees_only(): void
    {
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $ayse = $this->operator('Ayşe');

        $ahmetsRecord = $this->openCleaning($ahmet, $this->makeMachine(code: 'M01'));
        $withMehmet = $this->openCleaning($ayse, $this->makeMachine(code: 'M02'), helpers: [$mehmet]);

        // Mehmet güncel adımdan (1) çıkarıldı; 2. adımda hâlâ görevli ama bu yetmez.
        $removed = $this->openCleaning($ayse, $this->makeMachine(code: 'M03'), helpers: [$mehmet]);
        $this->workflow()->setWorkers($ayse, $this->stepOf($removed, 1), [$ayse->id]);

        // K-10: sahibi adımdan çıkarılsa da kaydın sahibi olarak işlem yapabilir.
        $ownerRemoved = $this->openCleaning($ayse, $this->makeMachine(code: 'M04'), helpers: [$mehmet]);
        $this->workflow()->setWorkers($ayse, $this->stepOf($ownerRemoved, 1), [$mehmet->id]);

        $this->assertMarked($mehmet, [$withMehmet, $ownerRemoved], [$ahmetsRecord, $removed]);
        $this->assertMarked($ahmet, [$ahmetsRecord], [$withMehmet, $removed, $ownerRemoved]);
        $this->assertMarked($ayse, [$withMehmet, $removed, $ownerRemoved], [$ahmetsRecord]);
        $this->assertMarked($this->manager(), [], [$ahmetsRecord, $withMehmet, $removed, $ownerRemoved]);
    }

    public function test_empty_state_is_shown_when_there_are_no_open_records(): void
    {
        $ahmet = $this->operator();
        $completed = $this->openCleaning($ahmet, $this->makeMachine());
        $this->completeRemainingSteps($ahmet, $completed);

        $response = $this->actingAs($ahmet)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Şu anda açık temizlik kaydı yok.')
            ->assertDontSee($completed->record_no);

        $this->assertNull($this->page($response)->querySelector('.open-cleanings__table'));
        $this->assertSame('0', $this->statValue($response, 'created'));
        $this->assertSame('0', $this->statValue($response, 'in-progress'));
        $this->assertSame('1', $this->statValue($response, 'completed-today'));
    }

    public function test_query_count_does_not_grow_with_the_number_of_open_records(): void
    {
        $viewer = $this->operator('İzleyici');
        $first = $this->openCleaning($this->operator('Ahmet'), $this->makeMachine(code: 'M01'));
        $this->workflow()->startStep($first->owner, $this->stepOf($first, 1));

        $baseline = $this->countQueries(fn () => $this->actingAs($viewer)->get(route('dashboard'))->assertOk());

        foreach (['M02', 'M03', 'M04'] as $code) {
            $owner = $this->operator("Sahip {$code}");
            $cleaning = $this->openCleaning($owner, $this->makeMachine(code: $code), helpers: [$this->operator()]);
            $this->runStep($owner, $cleaning, 1);
            $this->workflow()->startStep($owner, $this->stepOf($cleaning, 2));
        }
        $this->openCleaning($this->operator('Beklemede'), $this->makeMachine(code: 'M05'));

        $this->assertSame($baseline, $this->countQueries(fn () => $this->actingAs($viewer)->get(route('dashboard'))->assertOk()));
    }

    // ---------------------------------------------------------------------------------------

    /**
     * @param  list<Cleaning>  $mine
     * @param  list<Cleaning>  $notMine
     */
    private function assertMarked(User $viewer, array $mine, array $notMine): void
    {
        $response = $this->actingAs($viewer)->get(route('dashboard'))->assertOk();

        foreach ($mine as $cleaning) {
            $this->assertTrue($this->isMarkedMine($this->rowOf($response, $cleaning)), "{$viewer->name}: {$cleaning->record_no} işaretli olmalı.");
        }

        foreach ($notMine as $cleaning) {
            $this->assertFalse($this->isMarkedMine($this->rowOf($response, $cleaning)), "{$viewer->name}: {$cleaning->record_no} işaretli olmamalı.");
        }
    }

    private function isMarkedMine(Element $row): bool
    {
        $marked = $row->classList->contains('open-cleanings__row--mine');
        $label = $row->querySelector('.mine-badge');

        $this->assertSame($marked, $label !== null, 'Satır sınıfı ile "Bana ait" etiketi tutarlı olmalı.');
        $this->assertTrue($label === null || trim($label->textContent) === 'Bana ait');

        return $marked;
    }

    private function page(TestResponse $response): HTMLDocument
    {
        return HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);
    }

    private function rowOf(TestResponse $response, Cleaning $cleaning): Element
    {
        foreach ($this->page($response)->querySelectorAll('.open-cleanings__row') as $row) {
            if (trim($row->querySelector('.record-no')->textContent) === $cleaning->record_no) {
                return $row;
            }
        }

        $this->fail("{$cleaning->record_no} açık kayıtlar tablosunda yok.");
    }

    private function rowText(Element $element): string
    {
        return trim(preg_replace('/\s+/u', ' ', $element->textContent));
    }

    private function currentStepText(TestResponse $response, Cleaning $cleaning): string
    {
        return $this->rowText($this->rowOf($response, $cleaning)->querySelector('.current-step'));
    }

    private function statValue(TestResponse $response, string $modifier): string
    {
        $number = $this->page($response)->querySelector(".dashboard-stat--{$modifier} .info-box-number");
        $this->assertNotNull($number, "{$modifier} sayacı yok.");

        return trim($number->textContent);
    }

    private function countQueries(Closure $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}

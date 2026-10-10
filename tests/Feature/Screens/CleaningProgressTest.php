<?php

namespace Tests\Feature\Screens;

use App\Enums\CancelReason;
use App\Models\Cleaning;
use App\Models\User;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\TestCase;

/**
 * Kayıt detayının sağ sütunundaki ilerleme göstergesi (docs/plan-detay-sekmeler.md, faz B):
 * açılış → fazlar ve adımları → kapanış. Her faz ve adımın durumu sınıfla ve metinle verilir;
 * düğümleri bağlayan çizgi bir sonraki düğüme ulaşıldıysa dolu (done), gelecekse kesikli
 * (pending), kayıt kapandığı için yapılmayacaksa soluktur (skipped).
 */
class CleaningProgressTest extends TestCase
{
    use BuildsCleaningFixtures, InteractsWithCleaningWorkflow, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('08:00:00');
    }

    public function test_not_started_record_shows_every_phase_and_step_as_upcoming(): void
    {
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine([['steps' => 2], ['steps' => 1]]));

        $response = $this->show($ahmet, $cleaning);

        $this->assertSame('0 / 3 adım tamamlandı', $this->text($this->one($response, '#progress .progress-tracker__count')));
        $this->assertSame('0', $this->one($response, '#progress [role="progressbar"]')->getAttribute('aria-valuenow'));
        $this->assertSame(['upcoming', 'upcoming'], $this->phaseStates($response));
        $this->assertSame(['pending', 'pending', 'pending'], $this->stepStates($response));
        $this->assertSame(['pending', 'pending', 'pending', 'pending', 'pending', 'pending'], $this->lines($response));

        $this->assertStringContainsString('Kayıt açıldı 09.10.2026 11:00', $this->text($this->one($response, '#progress .progress-milestone--start')));
        $end = $this->one($response, '#progress .progress-milestone--end');
        $this->assertTrue($this->hasClass($end, 'progress-milestone--upcoming'));
        $this->assertSame('Tamamlanacak', $this->text($this->one($response, '#progress .progress-milestone--end .progress-milestone__title')));

        // Sıradaki adım işaretli ve Şimdi sekmesine bağlanır.
        $next = $this->one($response, '#progress [aria-current="step"]');
        $this->assertStringContainsString('1. Faz 1 adım 1', $this->text($next));
        $this->assertSame('#now', $this->one($response, '#progress [aria-current="step"] .progress-step__now')->getAttribute('href'));
        $this->assertSame('Sıradaki', $this->text($this->one($response, '#progress .progress-step__now')));
    }

    public function test_in_progress_record_marks_done_current_and_upcoming_parts(): void
    {
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine([['steps' => 2], ['steps' => 2]]));
        $this->runStep($ahmet, $cleaning, 1);
        $second = $this->stepOf($cleaning, 2);
        $this->workflow()->startStep($ahmet, $second);

        $response = $this->show($ahmet, $cleaning);

        $this->assertSame('1 / 4 adım tamamlandı', $this->text($this->one($response, '#progress .progress-tracker__count')));
        $bar = $this->one($response, '#progress [role="progressbar"]');
        $this->assertSame('25', $bar->getAttribute('aria-valuenow'));
        $this->assertSame('4 adımdan 1 adım tamamlandı', $bar->getAttribute('aria-valuetext'));

        $this->assertSame(['current', 'upcoming'], $this->phaseStates($response));
        $this->assertSame(['completed', 'running', 'pending', 'pending'], $this->stepStates($response));
        // açılış→faz 1, faz 1→adım 1, adım 1→adım 2 ulaşıldı; adım 2'den sonrası gelecek.
        $this->assertSame(['done', 'done', 'done', 'pending', 'pending', 'pending', 'pending'], $this->lines($response));

        $phase = $this->text($this->one($response, '#progress-phase-'.$this->phaseOf($cleaning, 1)->id));
        $this->assertStringContainsString('1. faz Faz 1', $phase);
        $this->assertStringContainsString('Devam ediyor 1 / 2 adım', $phase);

        // Güncel adım: tek, Adımlar sekmesindeki adıma ve Şimdi sekmesine bağlanır.
        $current = $this->page($response)->querySelectorAll('#progress [aria-current="step"]');
        $this->assertCount(1, $current);
        $this->assertTrue($this->hasClass($current->item(0), 'progress-step--running'));
        $this->assertSame('#step-'.$second->id, $this->one($response, '#progress [aria-current="step"] .progress-step__link')->getAttribute('href'));
        $this->assertStringContainsString('Çalışıyor Şu an', $this->text($current->item(0)));

        // Tamamlanan adımda tamamlanma saati.
        $done = $this->one($response, '#progress .progress-step--completed');
        $this->assertStringContainsString('1. Faz 1 adım 1 Tamamlandı 11:01', $this->text($done));
        $this->assertTrue($this->hasClass($this->one($response, '#progress .progress-step--completed .progress-step__state'), 'visually-hidden'));
    }

    public function test_paused_step_is_shown_with_its_state(): void
    {
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine([['steps' => 2]]));
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $this->travel(60)->seconds();
        $this->workflow()->pauseStep($ahmet, $this->stepOf($cleaning, 1));

        $response = $this->show($ahmet, $cleaning);

        $this->assertSame(['current'], $this->phaseStates($response));
        $this->assertSame(['paused', 'pending'], $this->stepStates($response));
        $paused = $this->one($response, '#progress .progress-step--paused');
        $this->assertSame('step', $paused->getAttribute('aria-current'));
        $this->assertFalse($this->hasClass($this->one($response, '#progress .progress-step--paused .progress-step__state'), 'visually-hidden'));
        $this->assertStringContainsString('Duraklatıldı Şu an', $this->text($paused));
    }

    public function test_completed_record_shows_every_part_done_and_the_finish(): void
    {
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine([['steps' => 2], ['steps' => 1]]));
        $this->completeRemainingSteps($ahmet, $cleaning);

        $response = $this->show($ahmet, $cleaning->refresh());

        $this->assertSame('3 / 3 adım tamamlandı', $this->text($this->one($response, '#progress .progress-tracker__count')));
        $this->assertSame('100', $this->one($response, '#progress [role="progressbar"]')->getAttribute('aria-valuenow'));
        $this->assertSame(['completed', 'completed'], $this->phaseStates($response));
        $this->assertSame(['completed', 'completed', 'completed'], $this->stepStates($response));
        $this->assertSame(['done', 'done', 'done', 'done', 'done', 'done'], $this->lines($response));
        $this->assertNull($this->page($response)->querySelector('#progress [aria-current]'));

        // Tamamlanan fazda ölçülen süre: iki adım × 60 sn.
        $phase = $this->text($this->one($response, '#progress-phase-'.$this->phaseOf($cleaning, 1)->id));
        $this->assertStringContainsString('Tamamlandı 2 / 2 adım Süre 2 dk 00 sn', $phase);

        $end = $this->one($response, '#progress .progress-milestone--end');
        $this->assertTrue($this->hasClass($end, 'progress-milestone--completed'));
        $this->assertSame('Tamamlandı 09.10.2026 11:03', $this->text($end));
    }

    public function test_cancelled_record_marks_unfinished_parts_as_not_done(): void
    {
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine([['steps' => 2], ['steps' => 1]]));
        $this->runStep($ahmet, $cleaning, 1);
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 2));
        $this->at('09:00:00');
        $this->workflow()->cancel($this->manager('Zeynep'), $cleaning, CancelReason::Other, 'Arıza');

        $response = $this->show($ahmet, $cleaning->refresh());

        $this->assertSame('1 / 3 adım tamamlandı', $this->text($this->one($response, '#progress .progress-tracker__count')));
        $this->assertSame(['skipped', 'skipped'], $this->phaseStates($response));
        $this->assertSame(['completed', 'skipped', 'skipped'], $this->stepStates($response));
        // Başlamış faza ve adıma ulaşıldı; sonrası yapılmayacak.
        $this->assertSame(['done', 'done', 'done', 'skipped', 'skipped', 'skipped'], $this->lines($response));
        $this->assertNull($this->page($response)->querySelector('#progress [aria-current]'));

        // Başlamış ama bitmemiş faz ve adım yarıda kaldı; hiç başlamayanlar yapılmadı.
        $this->assertStringContainsString('Yarıda kaldı 1 / 2 adım', $this->text($this->one($response, '#progress-phase-'.$this->phaseOf($cleaning, 1)->id)));
        $this->assertStringContainsString('Yapılmadı 0 / 1 adım', $this->text($this->one($response, '#progress-phase-'.$this->phaseOf($cleaning, 2)->id)));
        $steps = $this->page($response)->querySelectorAll('#progress .progress-step--skipped .progress-step__state');
        $this->assertSame(['Yarıda kaldı', 'Yapılmadı'], array_map(fn (Element $state) => $this->text($state), iterator_to_array($steps)));

        $end = $this->one($response, '#progress .progress-milestone--end');
        $this->assertTrue($this->hasClass($end, 'progress-milestone--cancelled'));
        $this->assertSame('İptal edildi 09.10.2026 12:00', $this->text($end));
    }

    public function test_expired_record_was_never_started(): void
    {
        $cleaning = $this->openCleaning($this->operator('Ahmet'), $this->makeMachine([['steps' => 1]]));
        $this->at('08:31:00');
        $this->workflow()->expireStale();

        $response = $this->show($this->operator('İzleyici'), $cleaning->refresh());

        $this->assertSame(['skipped'], $this->phaseStates($response));
        $this->assertSame(['skipped'], $this->stepStates($response));
        $this->assertStringContainsString('Yapılmadı 0 / 1 adım', $this->text($this->one($response, '#progress .progress-phase')));
        $end = $this->one($response, '#progress .progress-milestone--end');
        $this->assertTrue($this->hasClass($end, 'progress-milestone--expired'));
        $this->assertSame('Süresi doldu 09.10.2026 11:31', $this->text($end));
    }

    public function test_phase_closed_below_its_minimum_is_flagged(): void
    {
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine([['steps' => 1, 'min_seconds' => 900], ['steps' => 1, 'min_seconds' => 900]]));
        $this->runStep($ahmet, $cleaning, 1, deviationReason: 'Hat durdu, temizlik kısa kesildi');

        $response = $this->show($ahmet, $cleaning);

        $short = $this->text($this->one($response, '#progress-phase-'.$this->phaseOf($cleaning, 1)->id));
        $this->assertStringContainsString('Minimum süre altında kapandı', $short);
        $this->assertStringContainsString('Süre 1 dk 00 sn', $short);
        $this->assertStringNotContainsString('Minimum süre altında', $this->text($this->one($response, '#progress-phase-'.$this->phaseOf($cleaning, 2)->id)));
    }

    public function test_operators_and_managers_see_the_same_progress(): void
    {
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine([['steps' => 2], ['steps' => 1]]));
        $this->runStep($ahmet, $cleaning, 1);
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 2));

        $owner = $this->progressHtml($this->show($ahmet, $cleaning));

        $this->assertSame($owner, $this->progressHtml($this->show($this->operator('İzleyici'), $cleaning)));
        $this->assertSame($owner, $this->progressHtml($this->show($this->manager('Zeynep'), $cleaning)));
    }

    // ---------------------------------------------------------------------------------------

    private function show(User $viewer, Cleaning $cleaning): TestResponse
    {
        return $this->actingAs($viewer)->get(route('cleanings.show', $cleaning))->assertOk();
    }

    private function page(TestResponse $response): HTMLDocument
    {
        return HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);
    }

    private function one(TestResponse $response, string $selector): Element
    {
        $element = $this->page($response)->querySelector($selector);
        $this->assertNotNull($element, "Sayfada '{$selector}' yok.");

        return $element;
    }

    private function text(Element $element): string
    {
        return trim(preg_replace('/\s+/u', ' ', $element->textContent));
    }

    private function hasClass(Element $element, string $class): bool
    {
        return in_array($class, preg_split('/\s+/', (string) $element->getAttribute('class')), true);
    }

    private function progressHtml(TestResponse $response): string
    {
        $page = $this->page($response);

        return $page->saveHtml($page->querySelector('#progress'));
    }

    /**
     * @return list<string>
     */
    private function phaseStates(TestResponse $response): array
    {
        return $this->modifiers($response, '#progress .progress-phase', 'progress-phase', ['completed', 'current', 'upcoming', 'skipped']);
    }

    /**
     * @return list<string>
     */
    private function stepStates(TestResponse $response): array
    {
        return $this->modifiers($response, '#progress .progress-step', 'progress-step', ['completed', 'running', 'paused', 'pending', 'skipped']);
    }

    /**
     * Her satırın bir sonraki düğüme giden çizgisi, sırayla (kapanış satırının çizgisi yoktur).
     *
     * @return list<string>
     */
    private function lines(TestResponse $response): array
    {
        return array_map(
            fn (string $line) => substr($line, strlen('line-')),
            $this->modifiers($response, '#progress .progress-row:not(.progress-milestone--end)', 'progress-row', ['line-done', 'line-pending', 'line-skipped']),
        );
    }

    /**
     * @param  list<string>  $known
     * @return list<string>
     */
    private function modifiers(TestResponse $response, string $selector, string $block, array $known): array
    {
        $states = [];

        foreach ($this->page($response)->querySelectorAll($selector) as $element) {
            $found = array_values(array_filter($known, fn (string $state) => $this->hasClass($element, "{$block}--{$state}")));
            $this->assertCount(1, $found, "'{$selector}' öğesinde tek durum sınıfı olmalı: ".$element->getAttribute('class'));
            $states[] = $found[0];
        }

        return $states;
    }
}

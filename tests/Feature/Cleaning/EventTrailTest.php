<?php

namespace Tests\Feature\Cleaning;

use App\Models\Cleaning;
use App\Models\CleaningEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\TestCase;

/**
 * Olay kaydı (R-46, R-49): her geçiş, işlemi yapanla ve sunucu saatiyle sıralı bir
 * olay yazar; zincir baştan doğrulanabilir; reddedilen işlem olay bırakmaz.
 */
class EventTrailTest extends TestCase
{
    use BuildsCleaningFixtures, InteractsWithCleaningWorkflow, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('08:00:00');
    }

    public function test_full_lifecycle_writes_every_transition_in_order_with_an_intact_chain(): void
    {
        [$cleaning] = $this->runFullLifecycle();

        $this->assertSame([
            'cleaning.opened',
            'material.added',
            'cleaning.started',
            'phase.started',
            'step.started',
            'step.paused',
            'step.resumed',
            'step.workers_changed',
            'step.completed',
            'step.started',
            'step.completed',
            'phase.completed',
            'phase.started',
            'step.started',
            'material.added',
            'material.voided',
            'step.completed',
            'phase.completed',
            'cleaning.completed',
        ], $this->eventTypes($cleaning));

        $this->assertEquals(range(1, 19), $cleaning->events()->pluck('sequence')->all());
        $this->assertEventChainIntact($cleaning);
    }

    public function test_each_event_records_who_did_it_and_when(): void
    {
        // R-49: kim, ne zaman, ne yaptı.
        [$cleaning, $ahmet, $mehmet] = $this->runFullLifecycle();

        $expected = [
            ['cleaning.opened', $ahmet, '08:00:00'],
            ['material.added', $ahmet, '08:00:00'],
            ['cleaning.started', $ahmet, '08:05:00'],
            ['phase.started', $ahmet, '08:05:00'],
            ['step.started', $ahmet, '08:05:00'],
            ['step.paused', $mehmet, '08:10:00'],
            ['step.resumed', $mehmet, '08:20:00'],
            ['step.workers_changed', $ahmet, '08:25:00'],
            ['step.completed', $ahmet, '08:30:00'],
            ['step.started', $ahmet, '08:31:00'],
            ['step.completed', $ahmet, '08:35:00'],
            ['phase.completed', $ahmet, '08:35:00'],
            ['phase.started', $mehmet, '08:36:00'],
            ['step.started', $mehmet, '08:36:00'],
            ['material.added', $mehmet, '08:37:00'],
            ['material.voided', $ahmet, '08:38:00'],
            ['step.completed', $mehmet, '08:40:00'],
            ['phase.completed', $mehmet, '08:40:00'],
            ['cleaning.completed', $mehmet, '08:40:00'],
        ];
        $events = $cleaning->events()->get();

        foreach ($expected as $index => [$type, $actor, $time]) {
            $event = $events[$index];
            $this->assertSame($type, $event->type);
            $this->assertEquals($actor->id, $event->actor_id, "{$type} olayını yapan");
            $this->assertMoment("2026-10-09 {$time}", $event->occurred_at, "{$type} olayının zamanı");
        }
    }

    public function test_timestamps_of_records_match_their_events(): void
    {
        // R-24, R-47: aynı çağrıdaki tüm zamanlar tek bir sunucu anıdır.
        [$cleaning] = $this->runFullLifecycle();
        $cleaning = $cleaning->fresh();

        $this->assertEquals($this->lastEvent($cleaning, 'cleaning.started')->occurred_at, $cleaning->started_at);
        $this->assertEquals($this->lastEvent($cleaning, 'cleaning.completed')->occurred_at, $cleaning->closed_at);
        $this->assertEquals($this->phaseOf($cleaning, 1)->completed_at, $this->stepOf($cleaning, 2)->completed_at);
        $this->assertMoment('2026-10-09 08:40:00', $this->stepOf($cleaning, 3)->completed_at);
    }

    public function test_rejected_operation_leaves_no_event_and_keeps_the_chain_intact(): void
    {
        $machine = $this->makeMachine([['steps' => 2]]);
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $cleaning = $this->openCleaning($ahmet, $machine);
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $count = CleaningEvent::count();

        $this->assertRuleViolation('not_allowed', fn () => $this->workflow()->pauseStep($mehmet, $this->stepOf($cleaning, 1)));
        $this->assertRuleViolation('step_out_of_order', fn () => $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 2)));
        $this->assertRuleViolation('invalid_transition', fn () => $this->workflow()->resumeStep($ahmet, $this->stepOf($cleaning, 1)));

        $this->assertSame($count, CleaningEvent::count());
        $this->travel(1)->minutes();
        $this->workflow()->pauseStep($ahmet, $this->stepOf($cleaning, 1));
        $this->assertEquals(range(1, $count + 1), $cleaning->events()->pluck('sequence')->all());
        $this->assertEventChainIntact($cleaning);
    }

    public function test_every_cleaning_has_its_own_event_sequence(): void
    {
        $m01 = $this->makeMachine(code: 'M01');
        $m02 = $this->makeMachine(code: 'M02');
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $first = $this->openCleaning($ahmet, $m01);
        $second = $this->openCleaning($mehmet, $m02);
        $this->workflow()->startStep($ahmet, $this->stepOf($first, 1));
        $this->workflow()->startStep($mehmet, $this->stepOf($second, 1));

        $this->assertEquals([1, 2, 3, 4], $first->events()->pluck('sequence')->all());
        $this->assertEquals([1, 2, 3, 4], $second->events()->pluck('sequence')->all());
        $this->assertEventChainIntact($first);
        $this->assertEventChainIntact($second);
    }

    /**
     * 2 faz (2 + 1 adım). Ahmet sahibi, Mehmet yardımcı.
     *  08:00 açılış (+1 malzeme) · 08:05 1. adım başlar · 08:10 Mehmet duraklatır ·
     *  08:20 Mehmet devam ettirir · 08:25 Ahmet, Mehmet'i 1. adımdan çıkarır ·
     *  08:30 1. adım biter · 08:31–08:35 2. adım (1. faz biter) · 08:36 Mehmet 3. adımı
     *  başlatır (2. faz) · 08:37 Mehmet malzeme ekler · 08:38 Ahmet ilk malzemeyi geçersiz
     *  kılar · 08:40 Mehmet 3. adımı bitirir (temizlik biter).
     *
     * @return array{0: Cleaning, 1: User, 2: User}
     */
    private function runFullLifecycle(): array
    {
        $machine = $this->makeMachine([['steps' => 2], ['steps' => 1]]);
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $workflow = $this->workflow();

        $this->at('08:00:00');
        $cleaning = $this->openCleaning($ahmet, $machine, [$mehmet], [$this->entry($this->makeMaterial('DET-01'))]);
        $this->at('08:05:00');
        $workflow->startStep($ahmet, $this->stepOf($cleaning, 1));
        $this->at('08:10:00');
        $workflow->pauseStep($mehmet, $this->stepOf($cleaning, 1));
        $this->at('08:20:00');
        $workflow->resumeStep($mehmet, $this->stepOf($cleaning, 1));
        $this->at('08:25:00');
        $workflow->setWorkers($ahmet, $this->stepOf($cleaning, 1), [$ahmet->id]);
        $this->at('08:30:00');
        $workflow->completeStep($ahmet, $this->stepOf($cleaning, 1));
        $this->at('08:31:00');
        $workflow->startStep($ahmet, $this->stepOf($cleaning, 2));
        $this->at('08:35:00');
        $workflow->completeStep($ahmet, $this->stepOf($cleaning, 2));
        $this->at('08:36:00');
        $workflow->startStep($mehmet, $this->stepOf($cleaning, 3));
        $this->at('08:37:00');
        $workflow->addMaterial($mehmet, $cleaning->fresh(), $this->entry($this->makeMaterial('RNS-02'), 'LOT-002'));
        $this->at('08:38:00');
        $workflow->voidMaterial($ahmet, $cleaning->materials()->orderBy('id')->first(), 'Yanlış ürün');
        $this->at('08:40:00');
        $workflow->completeStep($mehmet, $this->stepOf($cleaning, 3));

        return [$cleaning, $ahmet, $mehmet];
    }
}

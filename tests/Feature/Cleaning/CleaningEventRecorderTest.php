<?php

namespace Tests\Feature\Cleaning;

use App\Models\Cleaning;
use App\Models\CleaningEvent;
use App\Models\User;
use App\Services\Cleaning\CleaningEventRecorder;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\TestCase;

class CleaningEventRecorderTest extends TestCase
{
    use BuildsCleaningFixtures, RefreshDatabase;

    private CleaningEventRecorder $events;

    private User $owner;

    private Cleaning $cleaning;

    protected function setUp(): void
    {
        parent::setUp();

        $this->events = app(CleaningEventRecorder::class);
        $this->owner = $this->operator();
        $this->cleaning = $this->makeCleaningRecord($this->makeMachine(), $this->owner);
    }

    public function test_first_event_starts_the_chain(): void
    {
        $event = $this->events->record($this->cleaning, 'cleaning.opened', $this->owner, $this->at('10:00:00'), ['record_no' => 'X']);

        $this->assertSame(1, $event->sequence);
        $this->assertNull($event->previous_hash);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $event->hash);
        $this->assertSame($this->owner->id, $event->actor_id);
        $this->assertSame('cleaning.opened', $event->type);
        $this->assertSame('2026-10-09 10:00:00', $event->fresh()->occurred_at->format('Y-m-d H:i:s'));
    }

    public function test_each_event_links_to_the_previous_one(): void
    {
        [$first, $second, $third] = $this->recordChain();

        $this->assertSame([1, 2, 3], [$first->sequence, $second->sequence, $third->sequence]);
        $this->assertSame($first->hash, $second->previous_hash);
        $this->assertSame($second->hash, $third->previous_hash);
        $this->assertCount(3, array_unique([$first->hash, $second->hash, $third->hash]));
        $this->assertDatabaseCount('cleaning_events', 3);
    }

    public function test_each_cleaning_has_its_own_chain(): void
    {
        $this->recordChain();
        $other = $this->makeCleaningRecord($this->makeMachine(code: 'M04'), $this->owner);

        $event = $this->events->record($other, 'cleaning.opened', $this->owner, $this->at('11:00:00'));

        $this->assertSame(1, $event->sequence);
        $this->assertNull($event->previous_hash);
        $this->assertTrue($this->events->verify($other));
        $this->assertTrue($this->events->verify($this->cleaning));
    }

    public function test_hash_follows_the_documented_formula(): void
    {
        $event = $this->events->record($this->cleaning, 'step.started', $this->owner, $this->at('10:00:00'), [
            'step_id' => 5, 'sequence' => 1, 'worker_ids' => [2, 1],
        ]);

        $expected = hash('sha256', json_encode([
            null,
            $this->cleaning->id,
            1,
            'step.started',
            $this->owner->id,
            '2026-10-09 10:00:00',
            ['sequence' => 1, 'step_id' => 5, 'worker_ids' => [2, 1]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->assertSame($expected, $event->hash);
    }

    public function test_hash_ignores_key_order_but_not_list_order(): void
    {
        $hash = fn (array $payload) => $this->events->hash(null, 1, 1, 'step.started', 1, $this->at('10:00:00'), $payload);

        $this->assertSame(
            $hash(['b' => 1, 'a' => ['y' => 2, 'x' => 3]]),
            $hash(['a' => ['x' => 3, 'y' => 2], 'b' => 1]),
        );
        $this->assertNotSame($hash(['ids' => [1, 2]]), $hash(['ids' => [2, 1]]));
    }

    public function test_verify_passes_after_database_round_trip_with_reordered_payload_keys(): void
    {
        $payload = [
            'worker_ids' => [3, 1, 2],
            'step_id' => 7,
            'note' => 'Çözelti ğüşİ / 50°C',
            'a' => ['z' => 1, 'b' => ['y' => true, 'c' => null]],
        ];

        $this->events->record($this->cleaning, 'cleaning.opened', $this->owner, $this->at('10:00:00'));
        $event = $this->events->record($this->cleaning, 'step.started', $this->owner, $this->at('10:05:00'), $payload);
        $this->events->record($this->cleaning, 'step.paused', $this->owner, $this->at('10:15:00'), ['step_id' => 7]);

        $stored = $event->fresh()->payload;

        // MySQL JSON anahtarları yeniden sıralar; listeler olduğu gibi kalır.
        $this->assertNotSame(array_keys($payload), array_keys($stored));
        $this->assertSame([3, 1, 2], $stored['worker_ids']);
        $this->assertTrue($this->events->verify($this->cleaning));
    }

    public function test_occurred_at_is_stored_and_hashed_at_second_precision(): void
    {
        $this->events->record($this->cleaning, 'cleaning.opened', $this->owner, CarbonImmutable::parse('2026-10-09 10:00:00.789'));

        $event = $this->cleaning->events()->sole();

        $this->assertSame('2026-10-09 10:00:00', $event->occurred_at->format('Y-m-d H:i:s'));
        $this->assertTrue($this->events->verify($this->cleaning));
    }

    public function test_system_event_without_actor_is_part_of_the_chain(): void
    {
        $this->events->record($this->cleaning, 'cleaning.opened', $this->owner, $this->at('10:00:00'));
        $event = $this->events->record($this->cleaning, 'cleaning.expired', null, $this->at('10:30:00'), ['stale_after_minutes' => 30]);

        $this->assertNull($event->fresh()->actor_id);
        $this->assertSame(
            $this->events->hash($event->previous_hash, $this->cleaning->id, 2, 'cleaning.expired', null, $this->at('10:30:00'), ['stale_after_minutes' => 30]),
            $event->hash,
        );
        $this->assertTrue($this->events->verify($this->cleaning));
    }

    public function test_untouched_events_verify(): void
    {
        $this->recordChain();

        $this->assertTrue($this->events->verifyEvents($this->loadEvents()));
    }

    /**
     * @param  Closure(CleaningEvent): void  $tamper
     */
    #[DataProvider('tamperings')]
    public function test_verify_events_detects_a_changed_event(Closure $tamper): void
    {
        $this->recordChain();
        $events = $this->loadEvents();

        $tamper($events[1]);

        $this->assertFalse($this->events->verifyEvents($events));
    }

    /**
     * @return array<string, array{Closure(CleaningEvent): void}>
     */
    public static function tamperings(): array
    {
        return [
            'payload' => [function (CleaningEvent $event) {
                $event->payload = ['step_id' => 999] + $event->payload;
            }],
            // R-47: 10:05'te başlatılan adım sonradan 09:30 gösterilemez.
            'occurred_at' => [function (CleaningEvent $event) {
                $event->occurred_at = $event->occurred_at->setTime(9, 30);
            }],
            'type' => [function (CleaningEvent $event) {
                $event->type = 'step.completed';
            }],
            'actor' => [function (CleaningEvent $event) {
                $event->actor_id = null;
            }],
            'hash' => [function (CleaningEvent $event) {
                $event->hash = str_repeat('0', 64);
            }],
            'previous_hash' => [function (CleaningEvent $event) {
                $event->previous_hash = str_repeat('0', 64);
            }],
            'sequence' => [function (CleaningEvent $event) {
                $event->sequence = 5;
            }],
        ];
    }

    public function test_verify_events_detects_a_changed_event_even_if_its_hash_is_recomputed(): void
    {
        $this->recordChain();
        $events = $this->loadEvents();
        $tampered = $events[1];

        $tampered->occurred_at = $tampered->occurred_at->setTime(9, 30);
        $tampered->hash = $this->events->hash(
            $tampered->previous_hash, $tampered->cleaning_id, $tampered->sequence, $tampered->type,
            $tampered->actor_id, $tampered->occurred_at, $tampered->payload,
        );

        // Değişen olay kendi içinde tutarlı, ama sonraki olay eski hash'e bağlı.
        $this->assertFalse($this->events->verifyEvents($events));
    }

    public function test_verify_events_detects_a_removed_event(): void
    {
        $this->recordChain();

        $this->assertFalse($this->events->verifyEvents($this->loadEvents()->forget(1)));
        $this->assertFalse($this->events->verifyEvents($this->loadEvents()->forget(0)));
    }

    public function test_verify_events_detects_events_out_of_order(): void
    {
        $this->recordChain();

        $this->assertFalse($this->events->verifyEvents($this->loadEvents()->reverse()));
    }

    public function test_verify_events_rejects_events_of_another_cleaning(): void
    {
        $this->recordChain();
        $other = $this->makeCleaningRecord($this->makeMachine(code: 'M04'), $this->owner);
        $this->events->record($other, 'cleaning.opened', $this->owner, $this->at('10:00:00'));

        $mixed = $other->events()->get()->concat($this->loadEvents()->forget(0));

        $this->assertFalse($this->events->verifyEvents($mixed));
    }

    public function test_verify_detects_an_event_inserted_directly_into_the_database(): void
    {
        [, , $third] = $this->recordChain();

        DB::table('cleaning_events')->insert([
            'cleaning_id' => $this->cleaning->id,
            'sequence' => 4,
            'type' => 'cleaning.completed',
            'actor_id' => $this->owner->id,
            'payload' => '{}',
            'occurred_at' => '2026-10-09 11:00:00',
            'previous_hash' => $third->hash,
            'hash' => hash('sha256', 'sahte'),
        ]);

        $this->assertFalse($this->events->verify($this->cleaning));
    }

    /**
     * @return array{CleaningEvent, CleaningEvent, CleaningEvent}
     */
    private function recordChain(): array
    {
        return [
            $this->events->record($this->cleaning, 'cleaning.opened', $this->owner, $this->at('10:00:00'), [
                'record_no' => $this->cleaning->record_no, 'type' => 'planned', 'helper_ids' => [],
            ]),
            $this->events->record($this->cleaning, 'step.started', $this->owner, $this->at('10:05:00'), [
                'step_id' => 1, 'sequence' => 1, 'worker_ids' => [$this->owner->id],
            ]),
            $this->events->record($this->cleaning, 'step.paused', $this->owner, $this->at('10:15:00'), [
                'step_id' => 1,
            ]),
        ];
    }

    /**
     * @return Collection<int, CleaningEvent>
     */
    private function loadEvents(): Collection
    {
        return CleaningEvent::query()->where('cleaning_id', $this->cleaning->id)->orderBy('sequence')->get();
    }

    private function at(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse("2026-10-09 {$time}");
    }
}

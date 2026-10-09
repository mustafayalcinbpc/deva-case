<?php

namespace Tests\Feature\Audit;

use App\Models\AuditCheckpoint;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Feature\Audit\Concerns\BuildsAuditFixtures;
use Tests\TestCase;

/**
 * Olay zinciri kontrol noktaları (R-46): bütün zincir başlarının özeti, bir önceki kontrol
 * noktasına bağlı, veritabanında yalnızca eklemeye açık ve günlüğe tek satır JSON olarak yazılır.
 */
class AuditCheckpointTest extends TestCase
{
    use BuildsAuditFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAudit();
    }

    public function test_first_checkpoint_covers_every_chain_head(): void
    {
        $a = $this->cleaning('M01');
        $b = $this->cleaning('M02');
        $this->record($a, 'cleaning.opened', '10:00:00');
        $this->record($b, 'cleaning.opened', '10:01:00');
        $this->record($a, 'step.started', '10:05:00', ['step_id' => 1]);
        $headA = $this->record($a, 'step.paused', '10:15:00', ['step_id' => 1]);
        $headB = $this->record($b, 'step.started', '10:20:00', ['step_id' => 2]);

        $checkpoint = $this->checkpointAt('11:00:00');

        $this->assertSame(1, $checkpoint->sequence);
        $this->assertNull($checkpoint->previous_digest);
        $this->assertSame($headB->id, $checkpoint->max_event_id);
        $this->assertSame(5, $checkpoint->event_count);
        $this->assertSame(2, $checkpoint->cleaning_count);
        $this->assertSame([[$a->id, 3, $headA->hash], [$b->id, 2, $headB->hash]], $checkpoint->fresh()->heads);
        $this->assertSame('2026-10-09 11:00:00', $checkpoint->fresh()->created_at->format('Y-m-d H:i:s'));
    }

    public function test_digests_follow_the_documented_formula(): void
    {
        $a = $this->cleaning('M01');
        $b = $this->cleaning('M02');
        $headA = $this->record($a, 'cleaning.opened', '10:00:00');
        $headB = $this->record($b, 'cleaning.opened', '10:01:00');

        $checkpoint = $this->checkpointAt('11:00:00');

        $headsDigest = hash('sha256', "{$a->id}:1:{$headA->hash}\n{$b->id}:1:{$headB->hash}\n");
        $digest = hash('sha256', json_encode([
            'audit-checkpoint/v1', 1, null, $headB->id, 2, 2, $headsDigest, '2026-10-09T11:00:00Z',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->assertSame($headsDigest, $checkpoint->heads_digest);
        $this->assertSame($digest, $checkpoint->digest);
        $this->assertSame($headsDigest, $this->audit->headsDigest([
            $b->id => [1, $headB->hash],
            $a->id => [1, $headA->hash],
        ]));
    }

    public function test_nothing_is_created_without_events(): void
    {
        $this->assertNull($this->checkpointAt('11:00:00'));
        $this->assertDatabaseCount('audit_checkpoints', 0);
        $this->assertFileDoesNotExist($this->logPath);
    }

    public function test_nothing_is_created_when_no_events_were_added_since_the_last_checkpoint(): void
    {
        $this->record($this->cleaning(), 'cleaning.opened', '10:00:00');
        $this->checkpointAt('11:00:00');

        $this->travelTo($this->at('12:00:00'));
        $exitCode = Artisan::call('audit:checkpoint');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('yeni olay yok', Artisan::output());
        $this->assertDatabaseCount('audit_checkpoints', 1);
        $this->assertCount(1, $this->logLines());
    }

    public function test_each_checkpoint_links_to_the_previous_one(): void
    {
        $a = $this->cleaning('M01');
        $b = $this->cleaning('M02');
        $this->record($a, 'cleaning.opened', '10:00:00');
        $headB = $this->record($b, 'cleaning.opened', '10:01:00');
        $first = $this->checkpointAt('11:00:00');

        $headA = $this->record($a, 'step.started', '11:30:00', ['step_id' => 1]);
        $second = $this->checkpointAt('12:00:00');

        $c = $this->cleaning('M03');
        $headC = $this->record($c, 'cleaning.opened', '12:10:00');
        $third = $this->checkpointAt('13:00:00');

        $this->assertSame([1, 2, 3], [$first->sequence, $second->sequence, $third->sequence]);
        $this->assertSame($first->digest, $second->previous_digest);
        $this->assertSame($second->digest, $third->previous_digest);
        $this->assertCount(3, array_unique([$first->digest, $second->digest, $third->digest]));

        // Yalnızca o aralıkta ilerleyen zincirler kaydedilir; özet ise bütün başları kapsar.
        $this->assertSame([[$a->id, 2, $headA->hash]], $second->fresh()->heads);
        $this->assertSame([3, 2], [$second->event_count, $second->cleaning_count]);
        $this->assertSame($this->audit->headsDigest([
            $a->id => [2, $headA->hash],
            $b->id => [1, $headB->hash],
        ]), $second->heads_digest);

        $this->assertSame([[$c->id, 1, $headC->hash]], $third->fresh()->heads);
        $this->assertSame([4, 3, $headC->id], [$third->event_count, $third->cleaning_count, $third->max_event_id]);
    }

    public function test_command_writes_each_checkpoint_to_the_audit_log_as_one_json_line(): void
    {
        $cleaning = $this->cleaning();
        $this->record($cleaning, 'cleaning.opened', '10:00:00', ['note' => 'Çözelti ğüşİ / 50°C']);
        $this->travelTo($this->at('11:00:00'));

        $this->assertSame(0, Artisan::call('audit:checkpoint'));
        $this->assertStringContainsString('Kontrol noktası #1 oluşturuldu', Artisan::output());

        $this->record($cleaning, 'step.started', '11:30:00');
        $this->travelTo($this->at('12:00:00'));
        Artisan::call('audit:checkpoint');

        $lines = $this->logLines();
        $checkpoints = AuditCheckpoint::query()->orderBy('sequence')->get();

        $this->assertCount(2, $lines);

        foreach ($checkpoints as $index => $checkpoint) {
            $entry = json_decode($lines[$index], true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame($this->audit->toLogEntry($checkpoint), $entry);
            // Satır kendi başına doğrulanabilir.
            $this->assertSame($entry['digest'], $this->audit->digest(
                $entry['sequence'], $entry['previous_digest'], $entry['max_event_id'], $entry['event_count'],
                $entry['cleaning_count'], $entry['heads_digest'], $entry['created_at'],
            ));
        }

        $this->assertSame('2026-10-09T12:00:00Z', json_decode($lines[1], true)['created_at']);
    }

    public function test_checkpoints_cannot_be_updated_or_deleted_at_database_level(): void
    {
        // R-46: Eloquent atlanıp doğrudan SQL kullanılsa da kontrol noktası değişmez.
        $this->record($this->cleaning(), 'cleaning.opened', '10:00:00');
        $checkpoint = $this->checkpointAt('11:00:00');

        $this->assertThrows(
            fn () => DB::table('audit_checkpoints')->where('id', $checkpoint->id)->update(['event_count' => 99]),
            QueryException::class,
        );
        $this->assertThrows(
            fn () => DB::table('audit_checkpoints')->where('id', $checkpoint->id)->delete(),
            QueryException::class,
        );

        $this->assertSame(1, $checkpoint->fresh()->event_count);
        $this->assertDatabaseCount('audit_checkpoints', 1);
    }

    public function test_checkpoints_cannot_be_changed_or_deleted_through_eloquent(): void
    {
        $this->record($this->cleaning(), 'cleaning.opened', '10:00:00');
        $checkpoint = $this->checkpointAt('11:00:00');

        $this->assertThrows(fn () => $checkpoint->update(['event_count' => 99]), LogicException::class);
        $this->assertThrows(fn () => $checkpoint->fresh()->delete(), LogicException::class);

        $this->assertSame(1, $checkpoint->fresh()->event_count);
    }

    public function test_checkpoint_and_verification_are_scheduled(): void
    {
        Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertMatchesRegularExpression('/0\s+\*\s+\*\s+\*\s+\*\s+php artisan audit:checkpoint\b/', $output);
        $this->assertMatchesRegularExpression('/30\s+3\s+\*\s+\*\s+\*\s+php artisan audit:verify --log\b/', $output);
        $this->assertStringContainsString('cleanings:expire-stale', $output);
    }
}

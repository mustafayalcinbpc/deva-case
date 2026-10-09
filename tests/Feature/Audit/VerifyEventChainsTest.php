<?php

namespace Tests\Feature\Audit;

use App\Models\AuditCheckpoint;
use App\Models\Cleaning;
use App\Models\CleaningEvent;
use App\Services\Cleaning\AuditVerificationReport;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Feature\Audit\Concerns\BuildsAuditFixtures;
use Tests\TestCase;

/**
 * audit:verify (R-46–R-48): kayıt zincirleri, kontrol noktaları ve günlük birlikte doğrulanır.
 *
 * Olay tablosundaki trigger'lar UPDATE/DELETE'i engeller ve DDL MySQL'de transaction'ı
 * kapattığı için testlerde trigger kaldırılmaz. Değişiklik iki yolla canlandırılır:
 *  - INSERT yetkisi olan birinin yazabileceği sahte satırlar (veritabanında, komutla);
 *  - tam yetkili birinin değiştirdiği olaylar ve kontrol noktaları (bellekte, servisle).
 */
class VerifyEventChainsTest extends TestCase
{
    use BuildsAuditFixtures, RefreshDatabase;

    private Cleaning $a;

    private Cleaning $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAudit();

        $this->a = $this->cleaning('M01');
        $this->b = $this->cleaning('M02');
    }

    public function test_untouched_records_and_checkpoints_verify(): void
    {
        $this->history();
        $this->record($this->a, 'step.completed', '12:30:00', ['step_id' => 1]);

        $this->travelTo($this->at('13:00:00'));
        $exitCode = Artisan::call('audit:verify', ['--log' => true]);
        $output = $this->consoleOutput();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('Doğrulama başarılı', $output);
        $this->assertStringContainsString('Kontrol noktası 2 (son: #2, 2026-10-09 12:00:00 UTC', $output);
        $this->assertStringContainsString('Son kontrol noktasından sonraki olay 1', $output);
        $this->assertStringContainsString('Günlük 2 satır', $output);

        $report = $this->audit->verify($this->logPath);

        $this->assertTrue($report->passed(), implode("\n", $report->problems));
        $this->assertSame([2, 6, 0, 2, 1, 2], [
            $report->cleanings, $report->events, $report->brokenChains, $report->checkpoints,
            $report->uncoveredEvents, $report->logEntries,
        ]);
    }

    public function test_records_without_checkpoints_verify_with_a_warning(): void
    {
        $this->record($this->a, 'cleaning.opened', '10:00:00');

        $this->assertSame(0, Artisan::call('audit:verify'));
        $this->assertStringContainsString('Henüz kontrol noktası yok', $this->consoleOutput());
    }

    public function test_result_is_logged(): void
    {
        $this->history();
        Log::spy();

        Artisan::call('audit:verify');
        $this->forgeEvent($this->a, 'step.completed', '10:30:00');
        Artisan::call('audit:verify');

        Log::shouldHaveReceived('info')->once()->with('audit:verify başarılı', Mockery::on(
            fn (array $summary) => $summary['checkpoints'] === 2 && $summary['problem_count'] === 0,
        ));
        Log::shouldHaveReceived('error')->once()->with('audit:verify başarısız', Mockery::on(
            fn (array $summary) => $summary['problem_count'] > 0 && $summary['problems'] !== [],
        ));
    }

    public function test_detects_a_backdated_event_appended_after_a_checkpoint(): void
    {
        // R-47, R-48: kontrol noktasından sonra, zinciri geçerli hash'le uzatan ve 10:30'da
        // yapılmış gibi görünen sahte bir "adım tamamlandı" olayı.
        $this->history();
        $this->forgeEvent($this->a, 'step.completed', '10:30:00');

        $this->assertTrue($this->recorder->verify($this->a), 'Kayıt zinciri tek başına yakalayamaz.');

        $this->travelTo($this->at('13:00:00'));
        $exitCode = Artisan::call('audit:verify');

        $output = $this->consoleOutput();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Doğrulama başarısız', $output);
        $this->assertStringContainsString('geçmişe dönük eklenmiş olay', $output);

        $report = $this->audit->verify();
        $forged = CleaningEvent::query()->where('cleaning_id', $this->a->id)->orderByDesc('sequence')->first();

        $this->assertProblem(
            "Olay #{$forged->id} (kayıt {$this->a->record_no} (#{$this->a->id})): kontrol noktası #2 (2026-10-09 12:00:00 UTC) "
            .'oluşturulduktan sonra eklenmiş ama 2026-10-09 10:30:00 UTC zamanını gösteriyor',
            $report,
        );
        $this->assertCount(1, $report->problems);
    }

    public function test_events_recorded_after_a_checkpoint_within_the_tolerance_verify(): void
    {
        // İşlem zamanı transaction başında alınır; kontrol noktası arada oluşmuş olabilir.
        $this->history();
        $this->record($this->a, 'step.completed', '11:56:00', ['step_id' => 1]);
        $this->record($this->b, 'step.started', '12:00:00', ['step_id' => 2]);

        $report = $this->audit->verify();

        $this->assertTrue($report->passed(), implode("\n", $report->problems));
        $this->assertSame(2, $report->uncoveredEvents);
    }

    public function test_detects_an_event_slipped_into_the_range_of_a_checkpoint(): void
    {
        // Geri alınan bir işlem olay tablosunda id boşluğu bırakır. INSERT yetkisi olan biri
        // kontrol noktası alındıktan sonra bu boşluğa, A'nın zincirini geçerli hash'le uzatan bir
        // olay yazarak kontrol noktasından önceki geçmişi değiştirmeye çalışır.
        $this->record($this->a, 'cleaning.opened', '10:00:00');
        $this->record($this->a, 'step.started', '10:05:00', ['step_id' => 1]);
        $this->record($this->b, 'cleaning.opened', '10:10:00');
        $gap = $this->burnEventId($this->b, '10:12:00');
        $this->record($this->b, 'step.started', '10:15:00', ['step_id' => 2]);
        $checkpoint = $this->checkpointAt('11:00:00');

        $this->assertGreaterThan($gap, $checkpoint->max_event_id);

        $this->forgeEvent($this->a, 'step.completed', '10:20:00', id: $gap);

        $this->assertTrue($this->recorder->verify($this->a), 'Kayıt zinciri tek başına yakalayamaz.');

        $this->assertSame(1, Artisan::call('audit:verify'));

        $report = $this->audit->verify();
        $name = 'Kontrol noktası #1 (2026-10-09 11:00:00 UTC)';

        $this->assertProblem("{$name}: kapsadığı aralıkta 4 olay vardı, şimdi 5 olay var.", $report);
        $this->assertProblem("{$name}: zincir başlarının özeti tutmuyor", $report);
        $this->assertProblem("{$name}, kayıt {$this->a->record_no} (#{$this->a->id}): kontrol noktasındaki son olay sıra 2 (", $report);
        $this->assertSame(0, $report->brokenChains);
    }

    public function test_detects_a_broken_chain(): void
    {
        // Zincire uymayan bir satır (yanlış hash) doğrudan yazılır.
        $this->history();
        $last = CleaningEvent::query()->where('cleaning_id', $this->b->id)->orderByDesc('sequence')->first();

        DB::table('cleaning_events')->insert([
            'cleaning_id' => $this->b->id,
            'sequence' => $last->sequence + 1,
            'type' => 'cleaning.completed',
            'actor_id' => $this->owner->id,
            'payload' => '{}',
            'occurred_at' => '2026-10-09 12:30:00',
            'previous_hash' => $last->hash,
            'hash' => hash('sha256', 'sahte'),
        ]);

        $this->assertSame(1, Artisan::call('audit:verify'));

        $report = $this->audit->verify();

        $this->assertSame(1, $report->brokenChains);
        $this->assertProblem("Kayıt {$this->b->record_no} (#{$this->b->id}): olay zinciri bozuk", $report);
    }

    public function test_detects_an_event_changed_after_a_checkpoint_even_if_the_chain_is_recomputed(): void
    {
        // R-47: tam yetkili biri A'nın 10:05'te başlatılan adımını 09:30'a çeker ve zincirin
        // geri kalanını yeniden hesaplar. Kayıt zinciri tutarlıdır; kontrol noktaları tutmaz.
        $this->history();
        $events = $this->events();
        $chain = $events->where('cleaning_id', $this->a->id)->sortBy('sequence')->values();

        $chain[1]->occurred_at = $chain[1]->occurred_at->setTime(9, 30);
        $this->rehash($chain);

        $this->assertTrue($this->recorder->verifyEvents($chain));

        $report = $this->verifyCheckpoints($events);
        $label = "kayıt {$this->a->record_no} (#{$this->a->id})";

        $this->assertProblem('Kontrol noktası #1 (2026-10-09 11:00:00 UTC): zincir başlarının özeti tutmuyor', $report);
        $this->assertProblem("Kontrol noktası #1 (2026-10-09 11:00:00 UTC), {$label}: kontrol noktasındaki son olay sıra 2 (", $report);
        $this->assertProblem('Kontrol noktası #2 (2026-10-09 12:00:00 UTC): zincir başlarının özeti tutmuyor', $report);
        $this->assertProblem("Kontrol noktası #2 (2026-10-09 12:00:00 UTC), {$label}: kontrol noktasındaki son olay sıra 3 (", $report);
    }

    public function test_detects_a_changed_event_without_recomputed_hashes(): void
    {
        $this->history();
        $events = $this->events();
        $chain = $events->where('cleaning_id', $this->a->id)->sortBy('sequence')->values();

        $chain[1]->payload = ['step_id' => 999];

        $this->assertFalse($this->recorder->verifyEvents($chain));
    }

    public function test_detects_a_changed_checkpoint(): void
    {
        $this->history();
        $checkpoints = $this->checkpoints();

        // Kontrol noktası daha erken alınmış gibi gösterilir.
        $checkpoints[0]->created_at = $checkpoints[0]->created_at->subDay();

        $report = $this->verifyCheckpoints($this->events(), $checkpoints);

        $this->assertProblem('Kontrol noktası #1: kayıtlı özet, kontrol noktasının alanlarıyla tutmuyor', $report);
    }

    public function test_detects_a_rewritten_checkpoint_chain(): void
    {
        $this->history();
        $checkpoints = $this->checkpoints();

        // Değiştirilen kontrol noktasının özeti de yeniden hesaplanırsa sonraki bağ kopar.
        $first = $checkpoints[0];
        $first->created_at = $first->created_at->subDay();
        $first->digest = $this->audit->digest(
            $first->sequence, $first->previous_digest, $first->max_event_id, $first->event_count,
            $first->cleaning_count, $first->heads_digest, $first->created_at,
        );

        $report = $this->verifyCheckpoints($this->events(), $checkpoints);

        $this->assertProblem('Kontrol noktası #2: bir önceki kontrol noktasının özetine bağlı değil.', $report);
    }

    public function test_detects_a_removed_checkpoint(): void
    {
        $this->history();
        $checkpoints = $this->checkpoints();
        unset($checkpoints[0]);

        $report = $this->verifyCheckpoints($this->events(), $checkpoints);

        $this->assertProblem('Kontrol noktası #2: sıra numarası #1 olmalıydı', $report);
        $this->assertProblem('Kontrol noktası #2: bir önceki kontrol noktasının özetine bağlı değil.', $report);
    }

    public function test_detects_events_removed_from_the_end(): void
    {
        $this->history();
        $events = $this->events();
        $events->pop();

        $report = $this->verifyCheckpoints($events);

        $this->assertProblem('Kontrol noktası #2 (2026-10-09 12:00:00 UTC): kapsadığı aralıkta 5 olay vardı, şimdi 4 olay var.', $report);
    }

    public function test_detects_checkpoints_rewritten_in_the_database_but_not_in_the_log(): void
    {
        // Tam yetkili biri zinciri ve kontrol noktalarını tutarlı biçimde yeniden yazsa da
        // sunucu dışındaki günlük satırları eskisi gibi kalır.
        $this->history();
        $checkpoints = $this->checkpoints();
        $previous = null;

        foreach ($checkpoints as $checkpoint) {
            $checkpoint->previous_digest = $previous;
            $checkpoint->heads_digest = hash('sha256', "yeniden yazılmış {$checkpoint->sequence}");
            $checkpoint->digest = $previous = $this->audit->digest(
                $checkpoint->sequence, $checkpoint->previous_digest, $checkpoint->max_event_id,
                $checkpoint->event_count, $checkpoint->cleaning_count, $checkpoint->heads_digest,
                $checkpoint->created_at,
            );
        }

        $report = new AuditVerificationReport;
        $this->audit->compareWithLog($checkpoints, $this->logPath, $report);

        $this->assertProblem('Kontrol noktası #1, günlüğün 1. satırıyla uyuşmuyor', $report);
        $this->assertProblem('Kontrol noktası #2, günlüğün 2. satırıyla uyuşmuyor', $report);
    }

    public function test_detects_a_changed_log_line(): void
    {
        $this->history();
        $lines = $this->logLines();
        $entry = json_decode($lines[0], true);
        $entry['event_count']++;
        $lines[0] = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->writeLog($lines);

        $this->assertSame(1, Artisan::call('audit:verify', ['--log' => true]));

        $report = $this->audit->verify($this->logPath);

        $this->assertProblem('Günlük satırı 1 (kontrol noktası #1): satırdaki özet, satırın alanlarıyla tutmuyor.', $report);
        $this->assertProblem('Kontrol noktası #1, günlüğün 1. satırıyla uyuşmuyor', $report);
    }

    public function test_detects_checkpoints_missing_from_the_log(): void
    {
        $this->history();
        $this->writeLog([$this->logLines()[0]]);

        $report = $this->audit->verify($this->logPath);

        $this->assertProblem('Kontrol noktası #2 günlükte yok', $report);
    }

    public function test_detects_logged_checkpoints_missing_from_the_database(): void
    {
        // Veritabanı yedekten geri yüklenmiş ya da son kontrol noktası silinmiş: günlükte #3 var.
        $this->history();
        $lines = $this->logLines();
        $entry = json_decode($lines[1], true);
        $entry['sequence'] = 3;
        $entry['previous_digest'] = $entry['digest'];
        $entry['max_event_id'] += 10;
        $entry['digest'] = $this->audit->digest(
            $entry['sequence'], $entry['previous_digest'], $entry['max_event_id'], $entry['event_count'],
            $entry['cleaning_count'], $entry['heads_digest'], $entry['created_at'],
        );
        $lines[] = json_encode($entry);
        $this->writeLog($lines);

        $report = $this->audit->verify($this->logPath);

        $this->assertProblem('Günlükteki kontrol noktası #3 (satır 3) veritabanında yok', $report);
        $this->assertCount(1, $report->problems);
    }

    public function test_detects_unreadable_log_lines_and_a_missing_log(): void
    {
        $this->history();
        $this->writeLog([...$this->logLines(), '{"format":"audit-checkpoint/v1","sequence":"bozuk"']);

        $report = $this->audit->verify($this->logPath);
        $this->assertProblem('Günlük satırı 3: kontrol noktası olarak okunamadı.', $report);

        unlink($this->logPath);

        $report = $this->audit->verify($this->logPath);
        $this->assertProblem("Günlük dosyası bulunamadı: {$this->logPath}", $report);
    }

    public function test_compares_with_an_external_copy_of_the_log(): void
    {
        $this->history();
        $copy = $this->logPath.'.kopya';
        copy($this->logPath, $copy);
        $this->beforeApplicationDestroyed(fn () => @unlink($copy));

        // Sunucudaki dosya silinse de dışarıdan geri alınan kopyayla doğrulanır.
        unlink($this->logPath);

        $exitCode = Artisan::call('audit:verify', ['--log-path' => $copy]);
        $output = $this->consoleOutput();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('Günlük 2 satır', $output);
    }

    /**
     * 10:00–10:20 arası iki kayıt, 11:00'de kontrol noktası #1; 11:30'da A ilerler, 12:00'de #2.
     */
    private function history(): void
    {
        $this->record($this->a, 'cleaning.opened', '10:00:00');
        $this->record($this->b, 'cleaning.opened', '10:01:00');
        $this->record($this->a, 'step.started', '10:05:00', ['step_id' => 1, 'worker_ids' => [$this->owner->id]]);
        $this->record($this->b, 'step.started', '10:20:00', ['step_id' => 2]);
        $this->checkpointAt('11:00:00');

        $this->record($this->a, 'step.paused', '11:30:00', ['step_id' => 1]);
        $this->checkpointAt('12:00:00');
    }

    /**
     * @return Collection<int, CleaningEvent>
     */
    private function events()
    {
        return CleaningEvent::query()->orderBy('id')->get();
    }

    /**
     * @return array<int, AuditCheckpoint>
     */
    private function checkpoints(): array
    {
        return AuditCheckpoint::query()->orderBy('sequence')->get()->all();
    }

    /**
     * @param  iterable<CleaningEvent>  $events
     * @param  iterable<AuditCheckpoint>|null  $checkpoints
     */
    private function verifyCheckpoints(iterable $events, ?iterable $checkpoints = null): AuditVerificationReport
    {
        $report = new AuditVerificationReport;
        $this->audit->verifyCheckpoints($events, $checkpoints ?? $this->checkpoints(), $report);

        $this->assertFalse($report->passed());

        return $report;
    }

    /**
     * Zinciri, ilk olaydan sonra baştan hesaplar (değişikliği gizlemeye çalışan biri gibi).
     *
     * @param  iterable<CleaningEvent>  $chain
     */
    private function rehash(iterable $chain): void
    {
        $previous = null;

        foreach ($chain as $event) {
            $event->previous_hash = $previous;
            $event->hash = $previous = $this->recorder->hash(
                $previous, $event->cleaning_id, $event->sequence, $event->type,
                $event->actor_id, $event->occurred_at, $event->payload,
            );
        }
    }

    private function consoleOutput(): string
    {
        // Artisan::output() tamponu boşaltır; her çağrıdan sonra bir kez okunur. Konsol bileşenleri iki sütun arasını noktayla doldurur ve uzun satırları kaydırabilir.
        return (string) preg_replace(['/\s*\.{3,}\s*/u', '/\s+/u'], ' ', Artisan::output());
    }
}

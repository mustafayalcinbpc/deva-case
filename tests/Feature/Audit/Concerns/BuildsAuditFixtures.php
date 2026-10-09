<?php

namespace Tests\Feature\Audit\Concerns;

use App\Models\AuditCheckpoint;
use App\Models\Cleaning;
use App\Models\CleaningEvent;
use App\Models\User;
use App\Services\Cleaning\AuditCheckpoints;
use App\Services\Cleaning\AuditVerificationReport;
use App\Services\Cleaning\CleaningEventRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;

/**
 * Kontrol noktası testleri için olay zinciri kurma ve sahte olay ekleme yardımcıları. Olaylar
 * doğrudan CleaningEventRecorder ile yazılır; INSERT yetkisi olan birinin yapabileceği de budur.
 */
trait BuildsAuditFixtures
{
    use BuildsCleaningFixtures;

    protected CleaningEventRecorder $recorder;

    protected AuditCheckpoints $audit;

    protected User $owner;

    protected string $logPath;

    protected function setUpAudit(): void
    {
        $this->recorder = app(CleaningEventRecorder::class);
        $this->audit = app(AuditCheckpoints::class);
        $this->owner = $this->operator();

        // Testler gerçek storage/logs/audit-checkpoints.log dosyasına yazmaz.
        $this->logPath = sys_get_temp_dir().'/audit-checkpoints-'.Str::random(12).'.log';
        config(['logging.channels.audit.path' => $this->logPath]);

        $this->beforeApplicationDestroyed(fn () => @unlink($this->logPath));
    }

    protected function cleaning(string $machineCode = 'M03'): Cleaning
    {
        return $this->makeCleaningRecord($this->makeMachine(code: $machineCode), $this->owner);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function record(Cleaning $cleaning, string $type, string $time, array $payload = []): CleaningEvent
    {
        return $this->recorder->record($cleaning, $type, $this->owner, $this->at($time), $payload);
    }

    protected function checkpointAt(string $time): ?AuditCheckpoint
    {
        $this->travelTo($this->at($time));

        return $this->audit->create();
    }

    /**
     * Olayı yazıp transaction'ı geri alır; auto-increment id harcanır ve olay tablosunda boşluk
     * kalır (üretimde kural ihlaliyle geri alınan işlemler de böyle boşluk bırakır).
     */
    protected function burnEventId(Cleaning $cleaning, string $time): int
    {
        $id = null;

        try {
            DB::transaction(function () use ($cleaning, $time, &$id) {
                $id = $this->record($cleaning, 'step.started', $time)->id;

                throw new RuntimeException('geri al');
            });
        } catch (RuntimeException) {
        }

        $this->assertDatabaseMissing('cleaning_events', ['id' => $id]);

        return $id;
    }

    /**
     * INSERT yetkisi olan birinin, kaydın zincirini geçerli hash'le uzatan sahte bir olay yazması.
     * Kayıt bazındaki zincir bu olayı tek başına yakalayamaz.
     */
    protected function forgeEvent(Cleaning $cleaning, string $type, string $time, ?int $id = null): void
    {
        $last = CleaningEvent::query()->where('cleaning_id', $cleaning->id)->orderByDesc('sequence')->firstOrFail();
        $sequence = $last->sequence + 1;
        $at = $this->at($time);

        $row = [
            'cleaning_id' => $cleaning->id,
            'sequence' => $sequence,
            'type' => $type,
            'actor_id' => $this->owner->id,
            'payload' => '{}',
            'occurred_at' => $at->format('Y-m-d H:i:s'),
            'previous_hash' => $last->hash,
            'hash' => $this->recorder->hash($last->hash, $cleaning->id, $sequence, $type, $this->owner->id, $at, []),
        ];

        DB::table('cleaning_events')->insert($id === null ? $row : ['id' => $id] + $row);
    }

    protected function at(string $time, string $date = '2026-10-09'): CarbonImmutable
    {
        return CarbonImmutable::parse("{$date} {$time}");
    }

    /**
     * @return list<string>
     */
    protected function logLines(): array
    {
        return file($this->logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    }

    /**
     * @param  list<string>  $lines
     */
    protected function writeLog(array $lines): void
    {
        file_put_contents($this->logPath, implode("\n", $lines)."\n");
    }

    protected function assertProblem(string $needle, AuditVerificationReport $report): void
    {
        foreach ($report->problems as $problem) {
            if (str_contains($problem, $needle)) {
                $this->addToAssertionCount(1);

                return;
            }
        }

        $this->fail("Beklenen sorun bulunamadı: {$needle}\nBulunanlar:\n- ".implode("\n- ", $report->problems));
    }
}

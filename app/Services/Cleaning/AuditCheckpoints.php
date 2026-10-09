<?php

namespace App\Services\Cleaning;

use App\Models\AuditCheckpoint;
use App\Models\Cleaning;
use App\Models\CleaningEvent;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;

/**
 * Olay zinciri kontrol noktaları (R-46–R-49).
 *
 * Kayıt bazındaki hash zincirinin dışarıda bir çapası yoktur: INSERT yetkisi olan biri zincirin
 * sonuna geçerli görünen bir olay ekleyebilir, tam yetkili biri bir zinciri baştan hesaplayıp
 * yeniden yazabilir (docs/plan.md, "Bilinen sınırlar"). Kontrol noktası, bir andaki bütün zincir
 * başlarını tek bir özette toplar ve bir önceki kontrol noktasına bağlar:
 *
 *   baş          = her kayıt için, id <= max_event_id olan olaylarından sequence'ı en büyük olan
 *   heads_digest = sha256("cleaning_id:sequence:hash\n" satırları, cleaning_id sırasıyla)
 *   digest       = sha256(json_encode([FORMAT, sequence, previous_digest, max_event_id,
 *                                      event_count, cleaning_count, heads_digest, created_at]))
 *                  created_at: UTC, "2026-10-09T15:00:00Z"
 *
 * Aynı kontrol noktası "audit" log kanalına tek satır JSON olarak da yazılır. O dosya sunucu
 * dışına taşındığında (log toplayıcı, değiştirilemez depolama) zincirin dış çapası olur:
 * veritabanında geçmişi yeniden yazan biri dışarıdaki satırları değiştiremez.
 *
 * Doğrulama her kontrol noktasının özetini bugünkü olaylardan yeniden hesaplar ve şunları bulur:
 *  - kontrol noktasından önceki bir olayın değiştirilmesi ya da bir zincirin yeniden yazılması;
 *  - kontrol noktasının kapsadığı aralığa (id boşluğuna) sonradan olay eklenmesi;
 *  - kontrol noktasından sonra eklenip ondan önceki bir zamanı gösteren olay.
 * Son kontrol noktasından sonra eklenen olaylar, bir sonraki kontrol noktasına kadar yalnızca
 * kayıt bazındaki zincirle korunur.
 */
final class AuditCheckpoints
{
    public const FORMAT = 'audit-checkpoint/v1';

    /**
     * Kontrol noktasından sonra eklenen bir olay, kontrol noktasından en çok bu kadar önceki bir
     * zamanı gösterebilir: işlem zamanı transaction başında alınır, olay sonunda yazılır.
     */
    public const BACKDATE_TOLERANCE_SECONDS = 300;

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    private const TIME_FORMAT = 'Y-m-d\TH:i:s\Z';

    /** @var array<int, string> */
    private array $labels = [];

    public function __construct(private readonly CleaningEventRecorder $recorder) {}

    /**
     * Son kontrol noktasından bu yana olay eklendiyse yeni kontrol noktası oluşturur ve günlüğe
     * yazar; eklenmediyse null döner.
     */
    public function create(): ?AuditCheckpoint
    {
        $previous = AuditCheckpoint::query()->orderByDesc('sequence')->first();
        $fromId = $previous?->max_event_id ?? 0;
        $maxEventId = (int) CleaningEvent::query()->max('id');

        if ($maxEventId <= $fromId) {
            return null;
        }

        $this->awaitPendingEvents($fromId, $maxEventId);

        $createdAt = now()->toImmutable()->setTimezone(config('app.timezone'))->startOfSecond();

        $touched = array_flip(DB::table('cleaning_events')
            ->whereBetween('id', [$fromId + 1, $maxEventId])
            ->distinct()
            ->pluck('cleaning_id')
            ->map(fn ($id) => (int) $id)
            ->all());

        $context = hash_init('sha256');
        $eventCount = 0;
        $cleaningCount = 0;
        $heads = [];

        foreach ($this->headsAt($maxEventId) as $row) {
            $head = [(int) $row->cleaning_id, (int) $row->sequence, (string) $row->hash];

            hash_update($context, $this->headLine(...$head));
            $eventCount += (int) $row->events;
            $cleaningCount++;

            if (isset($touched[$head[0]])) {
                $heads[] = $head;
            }
        }

        $headsDigest = hash_final($context);
        $sequence = ($previous?->sequence ?? 0) + 1;

        $checkpoint = AuditCheckpoint::create([
            'sequence' => $sequence,
            'max_event_id' => $maxEventId,
            'event_count' => $eventCount,
            'cleaning_count' => $cleaningCount,
            'heads' => $heads,
            'heads_digest' => $headsDigest,
            'previous_digest' => $previous?->digest,
            'digest' => $this->digest($sequence, $previous?->digest, $maxEventId, $eventCount, $cleaningCount, $headsDigest, $createdAt),
            'created_at' => $createdAt,
        ]);

        // Kayıt commit edildikten sonra yazılır. Yazılamazsa komut hata verir; doğrulama (--log)
        // bu kontrol noktasını "günlükte yok" diye raporlar.
        Log::channel('audit')->info(json_encode($this->toLogEntry($checkpoint), self::JSON_FLAGS));

        return $checkpoint;
    }

    /**
     * Zincirleri, kontrol noktalarını ve istenirse günlük dosyasını doğrular.
     */
    public function verify(?string $logPath = null): AuditVerificationReport
    {
        $report = new AuditVerificationReport;

        $this->verifyChains($report);

        $checkpoints = AuditCheckpoint::query()->orderBy('sequence')->get()->all();

        $this->verifyCheckpoints(
            DB::table('cleaning_events')->select(['id', 'cleaning_id', 'sequence', 'hash', 'occurred_at'])->lazyById(2000),
            $checkpoints,
            $report,
        );

        if ($logPath !== null) {
            $this->compareWithLog($checkpoints, $logPath, $report);
        }

        return $report;
    }

    /**
     * Her kaydın zincirini baştan hesaplar (CleaningEventRecorder::verify).
     */
    public function verifyChains(AuditVerificationReport $report): void
    {
        foreach (Cleaning::query()->select(['id', 'record_no'])->lazyById(500) as $cleaning) {
            $report->cleanings++;
            $this->labels[$cleaning->id] = "kayıt {$cleaning->record_no} (#{$cleaning->id})";

            if (! $this->recorder->verify($cleaning)) {
                $report->brokenChains++;
                $report->fail(ucfirst($this->label($cleaning->id)).': olay zinciri bozuk; bir olay değiştirilmiş, silinmiş ya da araya olay eklenmiş.');
            }
        }
    }

    /**
     * Kontrol noktalarının kendi zincirini ve her birinin özetini verilen olaylardan yeniden
     * hesaplayarak doğrular. Olaylar tek geçişte okunur.
     *
     * @param  iterable<object>  $events  id sırasıyla; id, cleaning_id, sequence, hash, occurred_at
     * @param  iterable<AuditCheckpoint>  $checkpoints  sequence sırasıyla
     */
    public function verifyCheckpoints(iterable $events, iterable $checkpoints, AuditVerificationReport $report): void
    {
        $checkpoints = array_values(is_array($checkpoints) ? $checkpoints : iterator_to_array($checkpoints, false));
        $total = count($checkpoints);

        $report->checkpoints = $total;
        $report->lastCheckpoint = $checkpoints[$total - 1] ?? null;

        $this->verifyCheckpointChain($checkpoints, $report);

        $heads = [];       // bugünkü olaylardan: cleaning_id => [sequence, hash]
        $expected = [];    // kontrol noktalarına kayıtlı başlardan: cleaning_id => [sequence, hash]
        $eventCount = 0;
        $next = 0;         // kapatılacak sıradaki kontrol noktası
        $covering = null;  // olay eklenmeden önceki son kontrol noktası
        $threshold = null;

        foreach ($events as $event) {
            $id = (int) $event->id;

            while ($next < $total && $checkpoints[$next]->max_event_id < $id) {
                $this->compareHeads($checkpoints[$next], $heads, $expected, $eventCount, $report);
                $covering = $checkpoints[$next++];
                $threshold = $covering->created_at
                    ->subSeconds(self::BACKDATE_TOLERANCE_SECONDS)
                    ->setTimezone(config('app.timezone'))
                    ->format('Y-m-d H:i:s');
            }

            if ($covering !== null && strcmp($this->storedTime($event->occurred_at), $threshold) < 0) {
                $report->fail(sprintf(
                    'Olay #%d (%s): kontrol noktası #%d (%s) oluşturulduktan sonra eklenmiş ama %s zamanını gösteriyor; geçmişe dönük eklenmiş olay.',
                    $id, $this->label((int) $event->cleaning_id), $covering->sequence,
                    $this->displayTime($covering->created_at), $this->displayTime($event->occurred_at),
                ));
            }

            if ($next === $total) {
                $report->uncoveredEvents++;
            }

            $cleaningId = (int) $event->cleaning_id;
            $sequence = (int) $event->sequence;

            if (! isset($heads[$cleaningId]) || $sequence > $heads[$cleaningId][0]) {
                $heads[$cleaningId] = [$sequence, (string) $event->hash];
            }

            $eventCount++;
        }

        // Kapsadığı olaylar artık bulunmayan kontrol noktaları (ör. sondaki olaylar silinmiş).
        while ($next < $total) {
            $this->compareHeads($checkpoints[$next++], $heads, $expected, $eventCount, $report);
        }

        $report->events = $eventCount;
    }

    /**
     * Veritabanındaki kontrol noktalarını günlük dosyasındaki JSON satırlarıyla karşılaştırır.
     * Dosya, sunucu dışındaki kopyadan geri alınmış olabilir.
     *
     * @param  iterable<AuditCheckpoint>  $checkpoints
     */
    public function compareWithLog(iterable $checkpoints, string $path, AuditVerificationReport $report): void
    {
        $report->logPath = $path;
        $stored = [];

        foreach ($checkpoints as $checkpoint) {
            $stored[$checkpoint->sequence] = $checkpoint;
        }

        if (! is_file($path)) {
            if ($stored !== []) {
                $report->fail("Günlük dosyası bulunamadı: {$path}");
            }

            return;
        }

        $logged = [];  // sequence => [satır no => kayıt]

        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            $lineNo = $index + 1;
            $entry = $this->parseLogEntry($line);

            if ($entry === null) {
                $report->fail("Günlük satırı {$lineNo}: kontrol noktası olarak okunamadı.");

                continue;
            }

            $report->logEntries++;

            if (! hash_equals($this->digestOfEntry($entry), $entry['digest'])) {
                $report->fail("Günlük satırı {$lineNo} (kontrol noktası #{$entry['sequence']}): satırdaki özet, satırın alanlarıyla tutmuyor.");
            }

            $logged[$entry['sequence']][$lineNo] = $entry;
        }

        foreach ($stored as $sequence => $checkpoint) {
            if (! isset($logged[$sequence])) {
                $report->fail("Kontrol noktası #{$sequence} günlükte yok; günlüğe yazılamamış ya da veritabanına sonradan eklenmiş.");

                continue;
            }

            $expected = $this->toLogEntry($checkpoint);

            foreach ($logged[$sequence] as $lineNo => $entry) {
                if (! $this->sameEntry($expected, $entry)) {
                    $report->fail("Kontrol noktası #{$sequence}, günlüğün {$lineNo}. satırıyla uyuşmuyor; veritabanındaki kontrol noktası değiştirilmiş ya da veritabanı sıfırlanmış.");
                }
            }
        }

        $missing = array_diff(array_keys($logged), array_keys($stored));
        // Doğrulama sürerken oluşturulan kontrol noktası günlüğe yazılmış olabilir.
        $createdMeanwhile = $missing === [] ? [] : AuditCheckpoint::query()
            ->whereIn('sequence', $missing)
            ->where('sequence', '>', $stored === [] ? 0 : max(array_keys($stored)))
            ->pluck('sequence')
            ->all();

        foreach (array_diff($missing, $createdMeanwhile) as $sequence) {
            $lines = implode(', ', array_keys($logged[$sequence]));
            $report->fail("Günlükteki kontrol noktası #{$sequence} (satır {$lines}) veritabanında yok; kontrol noktaları silinmiş ya da veritabanı geri alınmış.");
        }
    }

    /**
     * Kontrol noktasının günlüğe yazılan biçimi. Satır kendi başına doğrulanabilir: digest,
     * satırdaki alanlardan yeniden hesaplanır.
     *
     * @return array<string, mixed>
     */
    public function toLogEntry(AuditCheckpoint $checkpoint): array
    {
        return [
            'format' => self::FORMAT,
            'sequence' => $checkpoint->sequence,
            'created_at' => $this->time($checkpoint->created_at),
            'max_event_id' => $checkpoint->max_event_id,
            'event_count' => $checkpoint->event_count,
            'cleaning_count' => $checkpoint->cleaning_count,
            'previous_digest' => $checkpoint->previous_digest,
            'heads_digest' => $checkpoint->heads_digest,
            'digest' => $checkpoint->digest,
            'heads' => $checkpoint->heads,
        ];
    }

    public function logPath(): string
    {
        return (string) config('logging.channels.audit.path');
    }

    public function digest(
        int $sequence,
        ?string $previousDigest,
        int $maxEventId,
        int $eventCount,
        int $cleaningCount,
        string $headsDigest,
        DateTimeInterface|string $createdAt,
    ): string {
        return hash('sha256', json_encode([
            self::FORMAT,
            $sequence,
            $previousDigest,
            $maxEventId,
            $eventCount,
            $cleaningCount,
            $headsDigest,
            $createdAt instanceof DateTimeInterface ? $this->time($createdAt) : $createdAt,
        ], self::JSON_FLAGS));
    }

    /**
     * @param  array<int, array{0: int, 1: string}>  $heads  cleaning_id => [sequence, hash]
     */
    public function headsDigest(array $heads): string
    {
        ksort($heads);
        $context = hash_init('sha256');

        foreach ($heads as $cleaningId => [$sequence, $hash]) {
            hash_update($context, $this->headLine($cleaningId, $sequence, $hash));
        }

        return hash_final($context);
    }

    /**
     * Paylaşımlı kilitli okuma, kapsanacak aralığa olay yazmış ama henüz commit etmemiş
     * transaction'ları bekler. Yoksa sonradan commit edilen meşru bir olay, kontrol noktasını
     * bozulmuş gösterirdi.
     */
    private function awaitPendingEvents(int $fromId, int $toId): void
    {
        DB::transaction(fn () => DB::table('cleaning_events')
            ->whereBetween('id', [$fromId + 1, $toId])
            ->sharedLock()
            ->count());
    }

    /**
     * Her kaydın id <= $maxEventId olan olaylarından sequence'ı en büyük olanı ve olay sayısı.
     */
    private function headsAt(int $maxEventId): LazyCollection
    {
        $last = DB::table('cleaning_events')
            ->selectRaw('cleaning_id, MAX(sequence) AS sequence, COUNT(*) AS events')
            ->where('id', '<=', $maxEventId)
            ->groupBy('cleaning_id');

        return DB::table('cleaning_events as e')
            ->joinSub($last, 'h', fn ($join) => $join
                ->on('h.cleaning_id', '=', 'e.cleaning_id')
                ->on('h.sequence', '=', 'e.sequence'))
            ->orderBy('e.cleaning_id')
            ->select(['e.cleaning_id', 'e.sequence', 'e.hash', 'h.events'])
            ->cursor();
    }

    private function headLine(int $cleaningId, int $sequence, string $hash): string
    {
        return "{$cleaningId}:{$sequence}:{$hash}\n";
    }

    /**
     * @param  list<AuditCheckpoint>  $checkpoints
     */
    private function verifyCheckpointChain(array $checkpoints, AuditVerificationReport $report): void
    {
        $previous = null;

        foreach ($checkpoints as $index => $checkpoint) {
            $name = "Kontrol noktası #{$checkpoint->sequence}";

            if ($checkpoint->sequence !== $index + 1) {
                $report->fail("{$name}: sıra numarası #".($index + 1).' olmalıydı; kontrol noktası silinmiş ya da araya eklenmiş.');
            }

            if ($checkpoint->previous_digest !== $previous?->digest) {
                $report->fail("{$name}: bir önceki kontrol noktasının özetine bağlı değil.");
            }

            if ($previous !== null && $checkpoint->max_event_id <= $previous->max_event_id) {
                $report->fail("{$name}: kapsadığı olay aralığı bir öncekinden ileride değil.");
            }

            if (! hash_equals($this->digestOf($checkpoint), $checkpoint->digest)) {
                $report->fail("{$name}: kayıtlı özet, kontrol noktasının alanlarıyla tutmuyor; kontrol noktası değiştirilmiş.");
            }

            $previous = $checkpoint;
        }
    }

    /**
     * Kontrol noktasının kapsadığı olaylar okunduktan sonra çağrılır.
     *
     * @param  array<int, array{0: int, 1: string}>  $heads
     * @param  array<int, array{0: int, 1: string}>  $expected
     */
    private function compareHeads(AuditCheckpoint $checkpoint, array $heads, array &$expected, int $eventCount, AuditVerificationReport $report): void
    {
        foreach ($checkpoint->heads as [$cleaningId, $sequence, $hash]) {
            $expected[(int) $cleaningId] = [(int) $sequence, (string) $hash];
        }

        $name = "Kontrol noktası #{$checkpoint->sequence} ({$this->displayTime($checkpoint->created_at)})";

        if ($eventCount !== $checkpoint->event_count) {
            $report->fail("{$name}: kapsadığı aralıkta {$checkpoint->event_count} olay vardı, şimdi {$eventCount} olay var.");
        }

        if (count($heads) !== $checkpoint->cleaning_count) {
            $report->fail("{$name}: {$checkpoint->cleaning_count} kaydın zincirini kapsıyordu, şimdi ".count($heads).' kayıt var.');
        }

        if (hash_equals($checkpoint->heads_digest, $this->headsDigest($heads))) {
            return;
        }

        $report->fail("{$name}: zincir başlarının özeti tutmuyor; olay #{$checkpoint->max_event_id} ve öncesi, kontrol noktasından sonra değiştirilmiş ya da araya olay eklenmiş.");

        foreach (array_keys($heads + $expected) as $cleaningId) {
            $now = $heads[$cleaningId] ?? null;
            $then = $expected[$cleaningId] ?? null;

            if ($now !== $then) {
                $report->fail("{$name}, {$this->label($cleaningId)}: kontrol noktasındaki son olay {$this->describeHead($then)}, şimdi {$this->describeHead($now)}.");
            }
        }
    }

    private function digestOf(AuditCheckpoint $checkpoint): string
    {
        return $this->digest(
            $checkpoint->sequence, $checkpoint->previous_digest, $checkpoint->max_event_id,
            $checkpoint->event_count, $checkpoint->cleaning_count, $checkpoint->heads_digest,
            $checkpoint->created_at,
        );
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function digestOfEntry(array $entry): string
    {
        return $this->digest(
            $entry['sequence'], $entry['previous_digest'], $entry['max_event_id'],
            $entry['event_count'], $entry['cleaning_count'], $entry['heads_digest'],
            $entry['created_at'],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseLogEntry(string $line): ?array
    {
        $entry = json_decode($line, true);

        $valid = is_array($entry)
            && ($entry['format'] ?? null) === self::FORMAT
            && is_int($entry['sequence'] ?? null)
            && is_string($entry['created_at'] ?? null)
            && is_int($entry['max_event_id'] ?? null)
            && is_int($entry['event_count'] ?? null)
            && is_int($entry['cleaning_count'] ?? null)
            && array_key_exists('previous_digest', $entry)
            && ($entry['previous_digest'] === null || is_string($entry['previous_digest']))
            && is_string($entry['heads_digest'] ?? null)
            && is_string($entry['digest'] ?? null)
            && is_array($entry['heads'] ?? null);

        return $valid ? $entry : null;
    }

    /**
     * Alan sırasından bağımsız karşılaştırma; dışarıdaki kopya JSON'u yeniden yazmış olabilir.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function sameEntry(array $a, array $b): bool
    {
        ksort($a);
        ksort($b);

        return json_encode($a, self::JSON_FLAGS) === json_encode($b, self::JSON_FLAGS);
    }

    private function time(DateTimeInterface $at): string
    {
        return CarbonImmutable::instance($at)->utc()->format(self::TIME_FORMAT);
    }

    /**
     * Olay zamanı, sütunda saklandığı biçimde (uygulama saat dilimi, saniye).
     */
    private function storedTime(mixed $value): string
    {
        return $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s')
            : (string) $value;
    }

    private function displayTime(mixed $value): string
    {
        $at = $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse((string) $value, config('app.timezone'));

        return $at->utc()->format('Y-m-d H:i:s').' UTC';
    }

    private function label(int $cleaningId): string
    {
        if (! isset($this->labels[$cleaningId])) {
            $recordNo = Cleaning::query()->whereKey($cleaningId)->value('record_no');
            $this->labels[$cleaningId] = $recordNo === null ? "kayıt #{$cleaningId}" : "kayıt {$recordNo} (#{$cleaningId})";
        }

        return $this->labels[$cleaningId];
    }

    /**
     * @param  array{0: int, 1: string}|null  $head
     */
    private function describeHead(?array $head): string
    {
        return $head === null ? 'yok' : "sıra {$head[0]} (".substr($head[1], 0, 12).'…)';
    }
}

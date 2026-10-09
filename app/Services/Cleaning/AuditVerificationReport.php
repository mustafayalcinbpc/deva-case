<?php

namespace App\Services\Cleaning;

use App\Models\AuditCheckpoint;

/**
 * audit:verify sonucu: sayılar ve bulunan sorunlar (Türkçe, okunur cümleler).
 */
final class AuditVerificationReport
{
    public int $cleanings = 0;

    public int $brokenChains = 0;

    public int $events = 0;

    public int $checkpoints = 0;

    public ?AuditCheckpoint $lastCheckpoint = null;

    /** Son kontrol noktasından sonra eklenen, henüz yalnızca kayıt zinciriyle korunan olaylar. */
    public int $uncoveredEvents = 0;

    /** Günlükle karşılaştırıldıysa dosyanın yolu. */
    public ?string $logPath = null;

    public int $logEntries = 0;

    /** @var list<string> */
    public array $problems = [];

    public function fail(string $problem): void
    {
        $this->problems[] = $problem;
    }

    public function passed(): bool
    {
        return $this->problems === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'cleanings' => $this->cleanings,
            'broken_chains' => $this->brokenChains,
            'events' => $this->events,
            'checkpoints' => $this->checkpoints,
            'last_checkpoint' => $this->lastCheckpoint?->sequence,
            'uncovered_events' => $this->uncoveredEvents,
            'log_path' => $this->logPath,
            'log_entries' => $this->logPath === null ? null : $this->logEntries,
            'problem_count' => count($this->problems),
        ];
    }
}

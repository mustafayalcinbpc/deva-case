<?php

namespace App\Services\Cleaning;

use App\Models\Cleaning;
use App\Models\CleaningEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Temizlik kaydının olay zinciri (R-46–R-49). Her olay aynı kaydın bir önceki olayının
 * hash'ini içerir; bir olayın değiştirilmesi, silinmesi ya da araya olay eklenmesi zinciri bozar.
 */
final class CleaningEventRecorder
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /**
     * Çağıran, temizlik satırını kilitlemiş olmalı (ya da kayıt aynı transaction'da oluşturulmuş olmalı).
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function record(Cleaning $cleaning, string $type, ?User $actor, CarbonInterface $at, array $payload = []): CleaningEvent
    {
        $last = CleaningEvent::query()
            ->where('cleaning_id', $cleaning->id)
            ->orderByDesc('sequence')
            ->first(['sequence', 'hash']);

        $sequence = ($last?->sequence ?? 0) + 1;
        $previousHash = $last?->hash;

        // Sütun saniye hassasiyetinde, uygulama saat diliminde saklanır; hash de aynı değerle hesaplanır.
        $occurredAt = CarbonImmutable::instance($at)->setTimezone(config('app.timezone'))->startOfSecond();

        return CleaningEvent::create([
            'cleaning_id' => $cleaning->id,
            'sequence' => $sequence,
            'type' => $type,
            'actor_id' => $actor?->id,
            'payload' => $payload,
            'occurred_at' => $occurredAt,
            'previous_hash' => $previousHash,
            'hash' => $this->hash($previousHash, $cleaning->id, $sequence, $type, $actor?->id, $occurredAt, $payload),
        ]);
    }

    /**
     * Zinciri veritabanından baştan hesaplar; bir olay değişmiş ya da eksikse false.
     */
    public function verify(Cleaning $cleaning): bool
    {
        return $this->verifyEvents(
            CleaningEvent::query()->where('cleaning_id', $cleaning->id)->orderBy('sequence')->get(),
        );
    }

    /**
     * @param  iterable<CleaningEvent>  $events  sequence sırasıyla
     */
    public function verifyEvents(iterable $events): bool
    {
        $cleaningId = null;
        $expectedSequence = 1;
        $previousHash = null;

        foreach ($events as $event) {
            $cleaningId ??= (int) $event->cleaning_id;

            if ((int) $event->cleaning_id !== $cleaningId
                || (int) $event->sequence !== $expectedSequence
                || $event->previous_hash !== $previousHash) {
                return false;
            }

            $hash = $this->hash(
                $event->previous_hash,
                $event->cleaning_id,
                $event->sequence,
                $event->type,
                $event->actor_id,
                $event->occurred_at,
                $event->payload ?? [],
            );

            if (! hash_equals($hash, (string) $event->hash)) {
                return false;
            }

            $previousHash = $event->hash;
            $expectedSequence++;
        }

        return true;
    }

    /**
     * sha256(json_encode([previous_hash, cleaning_id, sequence, type, actor_id, occurred_at, kanonik payload]))
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function hash(?string $previousHash, int $cleaningId, int $sequence, string $type, ?int $actorId, CarbonInterface $occurredAt, array $payload): string
    {
        return hash('sha256', json_encode([
            $previousHash,
            $cleaningId,
            $sequence,
            $type,
            $actorId,
            $occurredAt->format('Y-m-d H:i:s'),
            $this->canonical($payload),
        ], self::JSON_FLAGS));
    }

    /**
     * Payload'ı veritabanından okunacağı biçime getirir (nesneler/enum'lar JSON karşılıklarına
     * döner) ve ilişkisel dizileri anahtara göre sıralar; MySQL JSON anahtar sırasını korumaz.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private function canonical(array $payload): array
    {
        return $this->sortKeys(json_decode(json_encode($payload, self::JSON_FLAGS), true, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function sortKeys(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(fn ($item) => is_array($item) ? $this->sortKeys($item) : $item, $value);
    }
}

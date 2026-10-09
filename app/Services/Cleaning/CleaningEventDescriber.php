<?php

namespace App\Services\Cleaning;

use App\Enums\CancelReason;
use App\Enums\CleaningType;
use App\Models\Cleaning;
use App\Models\CleaningEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Olay zincirindeki bir olayın Türkçe başlığı ve ayrıntıları (R-49). Kayıt detay ekranının olay
 * geçmişi ve denetim raporu aynı metni kullanır. Kaydın fazları, adımları ve malzemeleri ile
 * kişiler önceden yüklenmiş olmalıdır.
 */
final class CleaningEventDescriber
{
    /**
     * @param  Collection<int, User>  $users
     * @return array{title: string, details: list<array{label: string, value?: string, seconds?: int}>}
     */
    public function describe(CleaningEvent $event, Cleaning $cleaning, Collection $users): array
    {
        $payload = $event->payload ?? [];
        $step = $cleaning->steps->firstWhere('id', $payload['step_id'] ?? null);
        $phase = $cleaning->phases->firstWhere('id', $payload['phase_id'] ?? null);
        $item = $cleaning->materials->firstWhere('id', $payload['cleaning_material_id'] ?? null);

        $people = fn (string $key) => collect((array) ($payload[$key] ?? []))
            ->map(fn ($id) => $users->get((int) $id)?->name ?? "#{$id}")
            ->implode(', ');
        $text = fn (string $label, mixed $value) => filled($value) ? ['label' => $label, 'value' => (string) $value] : null;
        $duration = fn (string $label, string $key) => isset($payload[$key]) ? ['label' => $label, 'seconds' => (int) $payload[$key]] : null;

        $stepSequence = $step?->sequence ?? $payload['sequence'] ?? null;
        $phaseSequence = $phase?->sequence ?? $payload['sequence'] ?? null;
        $stepName = $stepSequence === null ? 'Adım' : "{$stepSequence}. adım";
        $phaseName = $phaseSequence === null ? 'Faz' : "{$phaseSequence}. faz";
        $stepTitle = $step?->procedureStep?->title;
        $phaseTitle = $phase?->procedurePhase?->name;
        $materialName = $item ? "{$item->material->code} — {$item->material->name}" : null;
        $version = $cleaning->procedureVersion;

        [$title, $details] = match ($event->type) {
            'cleaning.opened' => ['Kayıt açıldı', [
                $text('Tür', CleaningType::tryFrom((string) ($payload['type'] ?? ''))?->label()),
                $text('Prosedür', "{$version->procedure->code} — {$version->procedure->name} (versiyon {$version->version})"),
                $text('Yardımcı personel', $people('helper_ids')),
                $text('İş emri', ($payload['work_order_id'] ?? null) !== null ? $cleaning->workOrder?->code : null),
            ]],
            'cleaning.started' => ['Temizlik başladı (ilk adım başlatıldı)', [
                $text('Saha defteri referansı', $payload['field_ref'] ?? null),
            ]],
            'cleaning.completed' => ['Temizlik tamamlandı', [
                $duration('Net çalışma süresi', 'net_seconds'),
                $duration('Brüt süre', 'gross_seconds'),
                $duration('İnsan eforu', 'effort_seconds'),
            ]],
            'cleaning.cancelled' => ['Kayıt iptal edildi', [
                $text('Gerekçe', CancelReason::tryFrom((string) ($payload['reason'] ?? ''))?->label()),
                $text('Açıklama', $payload['note'] ?? null),
            ]],
            'cleaning.expired' => ['Kaydın süresi doldu', [
                $text('Neden', isset($payload['stale_after_minutes'])
                    ? "Açıldıktan sonra {$payload['stale_after_minutes']} dakika içinde ilk adım başlatılmadı."
                    : 'İlk adım süresi içinde başlatılmadı.'),
            ]],
            'phase.started' => ["{$phaseName} başladı", [
                $text('Faz', $phaseTitle),
            ]],
            'phase.completed' => ["{$phaseName} tamamlandı", [
                $text('Faz', $phaseTitle),
                $duration('Ölçülen süre', 'measured_seconds'),
                $duration('Minimum süre', 'minimum_seconds'),
                ($payload['below_minimum'] ?? false) ? $text('Sapma', 'Minimum sürenin altında') : null,
                $text('Gerekçe', $payload['deviation_reason'] ?? null),
            ]],
            'step.started' => ["{$stepName} başlatıldı", [
                $text('Adım', $stepTitle),
                $text('Görevliler', $people('worker_ids')),
            ]],
            'step.paused' => ["{$stepName} duraklatıldı", [
                $text('Adım', $stepTitle),
            ]],
            'step.resumed' => ["{$stepName} devam ettirildi", [
                $text('Adım', $stepTitle),
                $text('Görevliler', $people('worker_ids')),
            ]],
            'step.workers_changed' => ["{$stepName} görevlileri değişti", [
                $text('Adım', $stepTitle),
                $text('Eklenen', $people('added')),
                $text('Çıkarılan', $people('removed')),
            ]],
            'step.completed' => ["{$stepName} tamamlandı", [
                $text('Adım', $stepTitle),
            ]],
            'material.added' => ['Malzeme eklendi', [
                $text('Malzeme', $materialName),
                $text('Lot', $payload['lot_no'] ?? null),
                $text('Son kullanma tarihi', isset($payload['expiry_date']) ? $this->date((string) $payload['expiry_date']) : null),
            ]],
            'material.voided' => ['Malzeme geçersiz kılındı', [
                $text('Malzeme', $materialName),
                $text('Lot', $item?->lot_no),
                $text('Gerekçe', $payload['reason'] ?? null),
            ]],
            default => [$event->type, []],
        };

        return ['title' => $title, 'details' => array_values(array_filter($details))];
    }

    private function date(string $value): string
    {
        return rescue(fn () => CarbonImmutable::parse($value)->format('d.m.Y'), $value, report: false);
    }
}

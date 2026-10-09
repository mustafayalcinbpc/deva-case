<?php

namespace App\Notifications\Cleaning;

use Illuminate\Notifications\Notification;

/**
 * Temizlik kaydıyla ilgili veritabanı bildirimi. `data` biçimi ortak sözleşmedir
 * (docs/plan-yonetim-rapor-tasarim.md): title, message, url, level.
 *
 * Kuyruğa alınmaz: kuyruktaki dinleyicinin içinden gönderilir.
 */
abstract class CleaningNotification extends Notification
{
    public function __construct(
        public readonly int $cleaningId,
        public readonly string $recordNo,
    ) {}

    abstract public function title(): string;

    abstract public function message(): string;

    /**
     * @return 'info'|'warning'|'danger'
     */
    abstract public function level(): string;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{title: string, message: string, url: string, level: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title(),
            'message' => $this->message(),
            // Göreli adres: kuyruk işçisinde APP_URL'e bağlı kalmasın.
            'url' => route('cleanings.show', $this->cleaningId, absolute: false),
            'level' => $this->level(),
        ];
    }

    /**
     * x-duration bileşeniyle aynı biçim (ör. "4 dk 05 sn").
     */
    protected static function duration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $rest = $seconds % 60;

        return match (true) {
            $hours > 0 => sprintf('%d sa %02d dk', $hours, $minutes),
            $minutes > 0 => sprintf('%d dk %02d sn', $minutes, $rest),
            default => "{$rest} sn",
        };
    }
}

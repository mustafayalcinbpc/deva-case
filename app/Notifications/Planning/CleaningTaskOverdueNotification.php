<?php

namespace App\Notifications\Planning;

use App\Models\CleaningTask;
use Illuminate\Notifications\Notification;

/**
 * K-23: yapılması gereken temizliğin son tarihi geçti; aktif yöneticilere bir kez gider
 * (CleaningTaskGenerator::notifyOverdue). `data` biçimi ortak sözleşmedir: title, message, url,
 * level; task_id, görevin bildirildiğini anlamak içindir.
 */
class CleaningTaskOverdueNotification extends Notification
{
    public const TYPE = 'cleaning_task.overdue';

    private readonly int $taskId;

    private readonly string $machine;

    private readonly string $dueAt;

    public function __construct(CleaningTask $task)
    {
        $this->taskId = $task->id;
        $machine = $task->machine;
        $this->machine = collect([$machine?->line?->facility?->code, $machine?->line?->code, $machine?->code])->filter()->implode(' / ')
            .($machine ? " — {$machine->name}" : '');
        $this->dueAt = $task->due_at->setTimezone(config('app.display_timezone'))->format('d.m.Y H:i');
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return self::TYPE;
    }

    /**
     * @return array{title: string, message: string, url: string, level: string, task_id: int}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Temizlik gecikti',
            'message' => "{$this->machine}: yapılması gereken temizliğin son tarihi ({$this->dueAt}) geçti, kayıt açılmadı.",
            // Göreli adres: kuyruk işçisinde APP_URL'e bağlı kalmasın. Görevler gösterge panelinde listelenir.
            'url' => route('dashboard', absolute: false),
            'level' => 'warning',
            'task_id' => $this->taskId,
        ];
    }
}

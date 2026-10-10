{{--
    Planın etkin görevi (K-21, K-23): açık (son tarihi geçtiyse "Gecikti") ya da kayda bağlı.
    Etkin görev yoksa periyodik planda bir sonraki görevin zamanı gösterilir. activeTask yüklü olmalıdır.
--}}
@use('App\Enums\CleaningPlanKind')

@php
    $task = $plan->activeTask;
@endphp

@if ($task)
    <span class="cleaning-plan-task">
        @if ($task->isOverdue(now()))
            <span class="status-badge status-badge--overdue">Gecikti</span>
        @else
            <x-status-badge :status="$task->status" />
        @endif
        <span class="cleaning-plan-task__due">Son tarih: <x-datetime :value="$task->due_at" format="list" /></span>
    </span>
@elseif (! $plan->is_active)
    <span class="cleaning-plan-task cleaning-plan-task--none">—</span>
@elseif ($plan->kind === CleaningPlanKind::Periodic && $plan->interval_days)
    <span class="cleaning-plan-task cleaning-plan-task--next">
        Sonraki görev:
        @if ($plan->last_task_at)
            <x-datetime :value="$plan->last_task_at->addDays($plan->interval_days)" format="list" />
        @else
            ilk çalışmada
        @endif
    </span>
@else
    <span class="cleaning-plan-task cleaning-plan-task--none">Üretim iş emri tamamlanınca açılır</span>
@endif

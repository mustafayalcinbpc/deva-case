{{--
    Planın etkin görevi (K-21, K-23, K-24): ileride (vakti gelmemiş ya da üretim iş emrinin
    tamamlanmasını bekliyor), açık (son tarihi geçtiyse "Gecikti") ya da kayda bağlı. Etkin görev
    yoksa nedeni yazılır. activeTask yüklü olmalıdır.
--}}
@use('App\Enums\CleaningPlanKind')

@php
    $task = $plan->activeTask;
@endphp

@if ($task)
    <span class="cleaning-plan-task">
        @if ($task->isOverdue(now()))
            <span class="status-badge status-badge--overdue">Gecikti</span>
        @elseif ($task->isUpcoming(now()))
            <span class="status-badge status-badge--scheduled">İleride</span>
        @else
            <x-status-badge :status="$task->status" />
        @endif
        @if ($task->scheduled_at)
            <span class="cleaning-plan-task__due">Vakit: <x-datetime :value="$task->scheduled_at" format="list" /></span>
        @else
            <span class="cleaning-plan-task__due">{{ $task->triggerWorkOrder?->code ?? 'Üretim iş emri' }} tamamlanınca</span>
        @endif
    </span>
@elseif (! $plan->is_active)
    <span class="cleaning-plan-task cleaning-plan-task--none">—</span>
@elseif ($plan->kind === CleaningPlanKind::Periodic)
    <span class="cleaning-plan-task cleaning-plan-task--none">Sıradaki görev birazdan açılır</span>
@else
    <span class="cleaning-plan-task cleaning-plan-task--none">Makinede bekleyen üretim iş emri yok</span>
@endif

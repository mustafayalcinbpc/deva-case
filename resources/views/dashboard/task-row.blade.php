{{--
    Görev satırı (dashboard.tasks). $upcoming: müdahale vakti gelmemiş (K-24); "Kaydı aç" yerine
    vaktin ne zaman geleceği gösterilir. Vakti henüz belli olmayan görev, tetikleyen üretim iş
    emrinin tamamlanmasını bekler. $cancelTarget ve $canCancel kartın kendisinden gelir.
--}}
@php
    $machine = $task->machine;
    $overdue = $task->isOverdue($now);
    $isCancelTarget = $cancelTarget === $task->id;
    $trigger = $task->triggerWorkOrder;
@endphp

<tr id="task-{{ $task->id }}" @class(['due-tasks__row', 'due-tasks__row--overdue' => $overdue, 'due-tasks__row--upcoming' => $upcoming])>
    <td>
        <span class="location text-nowrap" title="{{ $machine->line->facility->name }} / {{ $machine->line->name }}">{{ $machine->line->facility->code }} / {{ $machine->line->code }} / {{ $machine->code }}</span>
        <span class="due-tasks__machine">{{ $machine->name }}</span>
    </td>
    <td class="due-tasks__source">
        {{ $task->reasonLabel() }}
        @if ($trigger)
            <span class="record-no due-tasks__trigger">{{ $trigger->code }}</span>
        @endif
    </td>
    <td>
        @if ($task->workOrder)
            <span class="record-no">{{ $task->workOrder->code }}</span>
            @if (filled($task->workOrder->product))
                <span class="due-tasks__product">{{ $task->workOrder->product }}</span>
            @endif
        @else
            <span class="text-body-secondary">—</span>
        @endif
    </td>
    <td class="due-tasks__when">
        @if ($task->scheduled_at)
            <x-datetime :value="$task->scheduled_at" format="list" class="text-nowrap" />
        @else
            <span class="due-tasks__waiting">Emir tamamlanınca</span>
            @if ($trigger?->planned_end_at)
                <span class="due-tasks__deadline">planlanan bitiş <x-datetime :value="$trigger->planned_end_at" format="list" /></span>
            @endif
        @endif

        @if ($upcoming)
            <span class="status-badge status-badge--scheduled due-tasks__upcoming">İleride</span>
        @elseif ($overdue)
            <span class="status-badge status-badge--overdue due-tasks__overdue">Gecikti</span>
        @else
            <span class="due-tasks__deadline">son tarih <x-datetime :value="$task->due_at" format="list" /></span>
        @endif
    </td>
    <td class="due-tasks__actions">
        @if ($upcoming)
            <span class="btn btn-outline-secondary btn-sm disabled due-tasks__locked" aria-disabled="true">
                <i class="bi bi-clock" aria-hidden="true"></i> Vakti gelince
            </span>
        @else
            <a href="{{ route('cleanings.create', ['task' => $task->id]) }}" class="btn btn-primary btn-sm due-tasks__open">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Kaydı aç
            </a>
        @endif

        @if ($canCancel)
            <details class="due-tasks__cancel" @if ($isCancelTarget) open @endif>
                <summary>Görevi iptal et</summary>
                <form method="POST" action="{{ route('tasks.cancel', $task) }}" class="due-tasks__cancel-form" data-module="submit-once">
                    @csrf
                    <input type="hidden" name="cancel_task_id" value="{{ $task->id }}">
                    <label for="cancel-reason-{{ $task->id }}" class="form-label">Gerekçe</label>
                    <textarea id="cancel-reason-{{ $task->id }}" name="cancel_reason" rows="2" required maxlength="2000"
                              @class(['form-control', 'is-invalid' => $isCancelTarget && $errors->has('cancel_reason')])>{{ $isCancelTarget ? old('cancel_reason') : '' }}</textarea>
                    @if ($isCancelTarget)
                        @error('cancel_reason')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    @endif
                    <p class="form-text">Görev silinmez; iptal edilir ve plan bir sonraki görevi üretir.</p>
                    <button type="submit" class="btn btn-outline-danger btn-sm">İptal et</button>
                </form>
            </details>
        @endif
    </td>
</tr>

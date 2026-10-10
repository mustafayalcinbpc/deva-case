{{--
    Yapılması gereken temizlikler (K-21, K-23): temizlik planlarının ürettiği açık görevler, son
    tarihi en yakın önce. Görevden herkes kayıt açabilir; kaydı açan sorumludur (R-15). Son tarihi
    geçen görev "Gecikti" ile işaretlenir. Görevi yalnızca yönetici gerekçe yazarak iptal eder.

    İptal formu her satırda ayrıdır; doğrulama hatasından sonra hatanın hangi satıra ait olduğu
    formla gönderilen cancel_task_id alanından anlaşılır.
--}}
@php
    $cancelTarget = (int) old('cancel_task_id');
    $canCancel = auth()->user()->can('manage-definitions');
@endphp

<section class="card due-tasks" aria-labelledby="due-tasks-title">
    <div class="card-header">
        <h2 class="card-title" id="due-tasks-title">Yapılması gereken temizlikler</h2>
        <span class="tag tag-neutral due-tasks__count" title="Açık görev sayısı">{{ $tasks->count() }}</span>
    </div>

    @if ($errors->has('task'))
        <div class="card-body pb-0">
            <div class="alert alert-danger due-tasks__error mb-0" role="alert">{{ $errors->first('task') }}</div>
        </div>
    @endif

    @if ($tasks->isEmpty())
        <div class="card-body">
            <p class="empty-state mb-0">Şu anda yapılması gereken temizlik yok.</p>
        </div>
    @else
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table align-middle mb-0 due-tasks__table">
                    <thead>
                        <tr>
                            <th scope="col">Makine</th>
                            <th scope="col">Neden</th>
                            <th scope="col">Sonraki üretim iş emri</th>
                            <th scope="col">Son tarih</th>
                            <th scope="col"><span class="visually-hidden">İşlemler</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tasks as $task)
                            @php
                                $machine = $task->machine;
                                $overdue = $task->isOverdue($now);
                                $isCancelTarget = $cancelTarget === $task->id;
                            @endphp
                            <tr id="task-{{ $task->id }}" @class(['due-tasks__row', 'due-tasks__row--overdue' => $overdue])>
                                <td>
                                    <span class="location text-nowrap" title="{{ $machine->line->facility->name }} / {{ $machine->line->name }}">{{ $machine->line->facility->code }} / {{ $machine->line->code }} / {{ $machine->code }}</span>
                                    <span class="due-tasks__machine">{{ $machine->name }}</span>
                                </td>
                                <td class="due-tasks__source">
                                    {{ $task->source->label() }}
                                    @if ($task->triggerWorkOrder)
                                        <span class="record-no due-tasks__trigger">{{ $task->triggerWorkOrder->code }}</span>
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
                                <td class="text-nowrap">
                                    <x-datetime :value="$task->due_at" />
                                    @if ($overdue)
                                        <span class="status-badge status-badge--overdue due-tasks__overdue">Gecikti</span>
                                    @endif
                                </td>
                                <td class="due-tasks__actions">
                                    <a href="{{ route('cleanings.create', ['task' => $task->id]) }}" class="btn btn-primary btn-sm due-tasks__open">
                                        <i class="bi bi-plus-lg" aria-hidden="true"></i> Kaydı aç
                                    </a>

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
                                                <p class="form-text">Görev silinmez; iptal edilir ve plan bir sonraki görevi üretebilir.</p>
                                                <button type="submit" class="btn btn-outline-danger btn-sm">İptal et</button>
                                            </form>
                                        </details>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</section>

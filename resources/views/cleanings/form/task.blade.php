{{--
    Görevden açılan kayıt (K-21): görevin özeti ve gizli görev alanı. Makine ve tür görevden gelir;
    kaydı açan sorumludur (R-15). Görevin makinesinde şu an kayıt açılamıyorsa (kullanımdan
    kaldırılmış ya da geçerli prosedürü yok) uyarılır; asıl kontrol workflow'dadır.
--}}
@php
    $taskMachine = $task->machine;
    $machineUsable = $machineGroups->flatten(1)->contains('id', $task->machine_id);
@endphp

<input type="hidden" name="cleaning_task_id" value="{{ $task->id }}">

<section class="card cleaning-form__section cleaning-form__task mb-3" aria-labelledby="cleaning-form-task">
    <div class="card-header">
        <h2 class="card-title" id="cleaning-form-task">Görevden kayıt</h2>
        @if ($task->isOverdue(now()))
            <span class="status-badge status-badge--overdue">Gecikti</span>
        @endif
    </div>

    <div class="card-body">
        <dl class="row g-3 mb-3 cleaning-form__task-facts">
            <div class="col-sm-6 col-xl-3">
                <dt>Makine</dt>
                <dd>{{ $taskMachine->code }} — {{ $taskMachine->name }}</dd>
            </div>
            <div class="col-sm-6 col-xl-3">
                <dt>Neden</dt>
                <dd>
                    {{ $task->source->label() }}
                    @if ($task->triggerWorkOrder)
                        · <span class="record-no">{{ $task->triggerWorkOrder->code }}</span>
                    @endif
                </dd>
            </div>
            <div class="col-sm-6 col-xl-3">
                <dt>Müdahale vakti</dt>
                <dd><x-datetime :value="$task->scheduled_at" format="list" /></dd>
            </div>
            <div class="col-sm-6 col-xl-3">
                <dt>Son tarih</dt>
                <dd><x-datetime :value="$task->due_at" format="list" /></dd>
            </div>
            @if ($task->workOrder)
                <div class="col-sm-6 col-xl-3">
                    <dt>Sonraki üretim iş emri</dt>
                    <dd><span class="record-no">{{ $task->workOrder->code }}</span></dd>
                </div>
            @endif
        </dl>

        <p class="form-text mb-0">Kayıt planlı temizlik olarak açılır ve sorumlusu siz olursunuz. Kayıt iptal edilir ya da süresi dolarsa görev yeniden açık olur.</p>

        @unless ($machineUsable)
            <div class="alert alert-warning mt-3 mb-0" role="alert">
                Bu makinede şu an kayıt açılamıyor: makine kullanımdan kaldırılmış ya da geçerli prosedürü yok.
            </div>
        @endunless
    </div>
</section>

{{--
    Yapılması gereken temizlikler (K-21, K-23, K-24): temizlik planlarının ürettiği açık görevler.
    Görev planlandığı an burada görünür. Vakti gelenler üstte, son tarihi en yakın önce; görevden
    herkes kayıt açabilir ve kaydı açan sorumludur (R-15). Son tarihi geçen görev "Gecikti" ile
    işaretlenir. Vakti gelmeyenler "İleride yapılacak" altında; vakti gelene kadar kayıt açılmaz.
    Görevi yalnızca yönetici gerekçe yazarak iptal eder.

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
        <span class="tag tag-neutral due-tasks__count" title="Vakti gelen görev sayısı">{{ $tasks->count() }}</span>
        @if ($upcomingTasks->isNotEmpty())
            <span class="tag tag-neutral due-tasks__upcoming-count" title="İleride yapılacak görev sayısı">{{ $upcomingTasks->count() }} ileride</span>
        @endif
    </div>

    @if ($errors->has('task'))
        <div class="card-body pb-0">
            <div class="alert alert-danger due-tasks__error mb-0" role="alert">{{ $errors->first('task') }}</div>
        </div>
    @endif

    @if ($tasks->isEmpty() && $upcomingTasks->isEmpty())
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
                            <th scope="col">Vakit</th>
                            <th scope="col"><span class="visually-hidden">İşlemler</span></th>
                        </tr>
                    </thead>
                    <tbody class="due-tasks__group due-tasks__group--due">
                        <tr class="due-tasks__group-title">
                            <th scope="colgroup" colspan="5">Vakti gelenler</th>
                        </tr>
                        @forelse ($tasks as $task)
                            @include('dashboard.task-row', ['task' => $task, 'upcoming' => false])
                        @empty
                            <tr class="due-tasks__none">
                                <td colspan="5" class="text-body-secondary">Şu anda vakti gelen temizlik yok.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if ($upcomingTasks->isNotEmpty())
                        <tbody class="due-tasks__group due-tasks__group--upcoming">
                            <tr class="due-tasks__group-title">
                                <th scope="colgroup" colspan="5">İleride yapılacak <span class="due-tasks__group-note">vakti gelince kayıt açılabilir</span></th>
                            </tr>
                            @foreach ($upcomingTasks as $task)
                                @include('dashboard.task-row', ['task' => $task, 'upcoming' => true])
                            @endforeach
                        </tbody>
                    @endif
                </table>
            </div>
        </div>
    @endif
</section>

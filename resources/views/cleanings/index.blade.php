@extends('layouts.app')

@section('title', 'Temizlik Kayıtları')
@section('page-title', 'Temizlik Kayıtları')

@if ($cleanings->total() > 0)
    @section('page-subtitle')
        {{ $cleanings->total() }} kayıt{{ $isFiltered ? ' (filtrelenmiş)' : '' }} · en yeni önce
    @endsection
@endif

@section('page-actions')
    <a href="{{ route('cleanings.create') }}" class="btn btn-primary cleaning-list__create">
        <i class="bi bi-plus-lg" aria-hidden="true"></i> Yeni kayıt
    </a>
@endsection

{{--
    Bütün temizlik kayıtları, en yeni önce. Herkes her kaydı görür (K-11); "Benim kayıtlarım"
    filtresi kaydın sahibi ya da herhangi bir adımında görevli olunan kayıtları gösterir.
    Liste sade tutulur: tür ve saha referansı kayıt no'nun, makine adı konumun altında; başlangıç
    ve kapanış zamanları kayıt detayındadır.
--}}
@section('content')
    <section class="card cleaning-list" aria-labelledby="cleaning-list-title">
        <h2 class="visually-hidden" id="cleaning-list-title">Kayıtlar</h2>

        <div class="card-body border-bottom cleaning-list__filters">
            <form method="GET" action="{{ route('cleanings.index') }}" class="cleaning-filters" role="search" aria-label="Kayıtları filtrele">
                <div class="cleaning-filters__field">
                    <label for="filter-status" class="form-label">Durum</label>
                    <select id="filter-status" name="status" class="form-select">
                        <option value="">Tümü</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}" @selected($filters['status'] === $status)>{{ $status->label() }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="cleaning-filters__field">
                    <label for="filter-machine" class="form-label">Makine</label>
                    <select id="filter-machine" name="machine_id" class="form-select">
                        <option value="">Tümü</option>
                        @foreach ($machineGroups as $location => $machines)
                            <optgroup label="{{ $location }}">
                                @foreach ($machines as $machine)
                                    <option value="{{ $machine->id }}" @selected($filters['machine_id'] === $machine->id)>
                                        {{ $machine->code }} — {{ $machine->name }}{{ $machine->is_active ? '' : ' (kullanımdan kaldırıldı)' }}
                                    </option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>

                <div class="cleaning-filters__field cleaning-filters__field--check">
                    <div class="form-check">
                        <input type="checkbox" id="filter-mine" name="mine" value="1" class="form-check-input" @checked($filters['mine'])>
                        <label for="filter-mine" class="form-check-label">Yalnızca benim kayıtlarım</label>
                    </div>
                    <div class="form-text">Sorumlusu olduğum ya da bir adımında görevli olduğum kayıtlar</div>
                </div>

                <div class="cleaning-filters__actions">
                    <button type="submit" class="btn btn-secondary cleaning-filters__submit">
                        <i class="bi bi-funnel" aria-hidden="true"></i> Filtrele
                    </button>
                    @if ($isFiltered)
                        <a href="{{ route('cleanings.index') }}" class="btn btn-link">Filtreyi temizle</a>
                    @endif
                </div>
            </form>
        </div>

        @if ($cleanings->isEmpty())
            <div class="card-body">
                <p class="empty-state mb-0">
                    @if ($isFiltered)
                        Filtreye uyan kayıt yok.
                    @else
                        Henüz temizlik kaydı yok.
                    @endif
                </p>
            </div>
        @else
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 cleaning-list__table">
                        <thead>
                            <tr>
                                <th scope="col">Kayıt</th>
                                <th scope="col">Makine</th>
                                <th scope="col">Sorumlu</th>
                                <th scope="col">Durum</th>
                                <th scope="col">Açılış</th>
                                <th scope="col" class="text-end" title="Adımlarda çalışılan süre; duraklamalar ve adımlar arası boşluklar sayılmaz">Net süre</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($cleanings as $row)
                                @php
                                    $cleaning = $row['cleaning'];
                                @endphp
                                <tr class="cleaning-list__row">
                                    <td>
                                        <a href="{{ route('cleanings.show', $cleaning) }}" class="record-no text-nowrap">{{ $cleaning->record_no }}</a>
                                        <span class="cell-sub text-nowrap">
                                            <span class="cleaning-list__type">{{ $cleaning->type->label() }}</span>
                                            @if ($cleaning->field_ref)
                                                · <span class="field-ref" title="Saha defteri referansı">{{ $cleaning->field_ref }}</span>
                                            @endif
                                        </span>
                                    </td>
                                    <td>
                                        <span class="location text-nowrap" title="{{ $cleaning->facility->name }} / {{ $cleaning->line->name }}">{{ $cleaning->facility->code }} / {{ $cleaning->line->code }} / {{ $cleaning->machine->code }}</span>
                                        <span class="cell-sub">{{ $cleaning->machine->name }}</span>
                                    </td>
                                    <td>{{ $cleaning->owner->name }}</td>
                                    <td><x-status-badge :status="$cleaning->status" /></td>
                                    <td class="text-nowrap"><x-datetime :value="$cleaning->created_at" format="list" /></td>
                                    <td class="text-nowrap text-end net-time">
                                        @if ($row['netSeconds'] === null)
                                            <span class="text-body-secondary">—</span>
                                        @else
                                            <x-duration :seconds="$row['netSeconds']" />
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            @if ($cleanings->hasPages())
                <div class="card-footer cleaning-list__pagination">
                    {{ $cleanings->links() }}
                </div>
            @endif
        @endif
    </section>
@endsection

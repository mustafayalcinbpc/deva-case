@extends('layouts.app')

@section('title', 'Temizlik Kayıtları')
@section('page-title', 'Temizlik Kayıtları')

{{--
    Bütün temizlik kayıtları, en yeni önce. Herkes her kaydı görür (K-11); "Benim kayıtlarım"
    filtresi kaydın sahibi ya da herhangi bir adımında görevli olunan kayıtları gösterir.
--}}
@section('content')
    <section class="card cleaning-list" aria-labelledby="cleaning-list-title">
        <div class="card-header">
            <h2 class="card-title" id="cleaning-list-title">Kayıtlar</h2>
            <div class="card-tools">
                <a href="{{ route('cleanings.create') }}" class="btn btn-primary cleaning-list__create">
                    <i class="bi bi-plus-circle" aria-hidden="true"></i> Yeni kayıt
                </a>
            </div>
        </div>

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
                    <button type="submit" class="btn btn-secondary">
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
                                <th scope="col">Kayıt no</th>
                                <th scope="col">Saha ref.</th>
                                <th scope="col">Konum</th>
                                <th scope="col">Tür</th>
                                <th scope="col">Sorumlu</th>
                                <th scope="col">Durum</th>
                                <th scope="col">Açılış</th>
                                <th scope="col">Başlangıç</th>
                                <th scope="col">Kapanış</th>
                                <th scope="col" title="Adımlarda çalışılan süre; duraklamalar ve adımlar arası boşluklar sayılmaz">Net süre</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($cleanings as $row)
                                @php($cleaning = $row['cleaning'])
                                <tr class="cleaning-list__row">
                                    <td>
                                        <a href="{{ route('cleanings.show', $cleaning) }}" class="record-no text-nowrap">{{ $cleaning->record_no }}</a>
                                    </td>
                                    <td class="text-nowrap field-ref">
                                        @if ($cleaning->field_ref)
                                            {{ $cleaning->field_ref }}
                                        @else
                                            <span class="text-body-secondary" title="{{ $cleaning->type->hasFieldReference() ? 'İlk adım başlatıldığında üretilir' : 'Plansız müdahale saha defterine işlenmez' }}">—</span>
                                        @endif
                                    </td>
                                    <td class="text-nowrap">
                                        <span class="location" title="{{ $cleaning->facility->name }} / {{ $cleaning->line->name }} / {{ $cleaning->machine->name }}">{{ $cleaning->facility->code }} / {{ $cleaning->line->code }} / {{ $cleaning->machine->code }}</span>
                                    </td>
                                    <td>{{ $cleaning->type->label() }}</td>
                                    <td>{{ $cleaning->owner->name }}</td>
                                    <td><x-status-badge :status="$cleaning->status" /></td>
                                    <td class="text-nowrap"><x-datetime :value="$cleaning->created_at" /></td>
                                    <td class="text-nowrap"><x-datetime :value="$cleaning->started_at" /></td>
                                    <td class="text-nowrap"><x-datetime :value="$cleaning->closed_at" /></td>
                                    <td class="text-nowrap net-time">
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

@extends('layouts.app')

@section('title', 'Makineler')
@section('page-title', 'Makineler')
@section('page-subtitle', 'Her makinenin tek geçerli prosedürü vardır; kullanımdan kaldırılan makine geçmiş kayıtlarda görünmeye devam eder.')

@section('page-actions')
    <a href="{{ route('admin.machines.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-circle" aria-hidden="true"></i> Yeni makine
    </a>
@endsection

{{-- Makine listesi (R-12, R-43, K-16, K-18): hat ve kullanım durumuna göre süzülür. --}}
@section('content')
    <section class="card machine-list" aria-labelledby="machine-list-title">
        <div class="card-header">
            <h2 class="card-title" id="machine-list-title">Makine listesi</h2>
        </div>

        <div class="card-body border-bottom machine-list__filters">
            <form method="GET" action="{{ route('admin.machines.index') }}" class="row g-3 align-items-end definition-filters" role="search" aria-label="Makineleri filtrele">
                <div class="col-sm-6 col-lg-4 definition-filters__field">
                    <label for="filter-line" class="form-label">Hat</label>
                    <select id="filter-line" name="line_id" class="form-select">
                        <option value="">Tümü</option>
                        @foreach ($lineGroups as $facility => $lines)
                            <optgroup label="{{ $facility }}">
                                @foreach ($lines as $line)
                                    <option value="{{ $line->id }}" @selected($filters['line_id'] === $line->id)>{{ $line->code }} — {{ $line->name }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>

                <div class="col-sm-6 col-lg-4 definition-filters__field">
                    <label for="filter-status" class="form-label">Durum</label>
                    <select id="filter-status" name="status" class="form-select">
                        <option value="">Tümü</option>
                        @foreach ($statuses as $value => $label)
                            <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-auto definition-filters__actions">
                    <button type="submit" class="btn btn-outline-secondary">
                        <i class="bi bi-funnel" aria-hidden="true"></i> Filtrele
                    </button>
                    @if ($isFiltered)
                        <a href="{{ route('admin.machines.index') }}" class="btn btn-link">Filtreyi temizle</a>
                    @endif
                </div>
            </form>
        </div>

        @if ($machines->isEmpty())
            <div class="card-body">
                <p class="empty-state mb-0">
                    @if ($isFiltered)
                        Filtreye uyan makine yok.
                    @else
                        Henüz makine tanımlanmamış.
                    @endif
                </p>
            </div>
        @else
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 machine-list__table">
                        <thead>
                            <tr>
                                <th scope="col">Makine</th>
                                <th scope="col">Konum</th>
                                <th scope="col">Prosedür</th>
                                <th scope="col">Durum</th>
                                <th scope="col">Açık kayıt</th>
                                <th scope="col"><span class="visually-hidden">İşlemler</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($machines as $machine)
                                <tr class="machine-list__row">
                                    <td>
                                        <a href="{{ route('admin.machines.show', $machine) }}" class="record-no">{{ $machine->code }}</a>
                                        <div>{{ $machine->name }}</div>
                                    </td>
                                    <td class="text-nowrap">
                                        <span title="{{ $machine->line->facility->name }} / {{ $machine->line->name }}">{{ $machine->line->facility->code }} / {{ $machine->line->code }}</span>
                                    </td>
                                    <td>
                                        @include('admin.machines.partials.procedure', [
                                            'procedure' => $machine->procedure,
                                            'version' => $machine->procedure?->current_version,
                                        ])
                                    </td>
                                    <td>@include('admin.machines.partials.state')</td>
                                    <td>
                                        @if ($machine->open_cleanings_count > 0)
                                            <a href="{{ route('admin.machines.show', $machine) }}#open-cleanings">{{ $machine->open_cleanings_count }}</a>
                                        @else
                                            <span class="text-body-secondary">—</span>
                                        @endif
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <a href="{{ route('admin.machines.edit', $machine) }}" class="btn btn-outline-secondary btn-sm">
                                            <i class="bi bi-pencil" aria-hidden="true"></i> Düzenle
                                            <span class="visually-hidden">{{ $machine->code }}</span>
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            @if ($machines->hasPages())
                <div class="card-footer machine-list__pagination">
                    {{ $machines->links() }}
                </div>
            @endif
        @endif
    </section>
@endsection

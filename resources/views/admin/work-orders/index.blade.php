@extends('layouts.app')

@section('title', 'Üretim İş Emirleri')
@section('page-title', 'Üretim İş Emirleri')
@section('page-subtitle', 'Üretim iş emirleri kayıt açılırken isteğe bağlı olarak seçilir; makineye ya da hatta bağlı üretim iş emri yalnızca orada kullanılabilir.')

@section('page-actions')
    <a href="{{ route('admin.work-orders.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-circle" aria-hidden="true"></i> Yeni üretim iş emri
    </a>
@endsection

{{--
    K-19: üretim iş emri bir makineye, bir hatta ya da hiçbirine bağlıdır. Hat filtresi o hatta bağlı
    üretim iş emirlerini ve hattın makinelerine bağlı olanları gösterir.
--}}
@section('content')
    <section class="card admin-list work-order-list" aria-labelledby="work-order-list-title">
        <div class="card-header">
            <h2 class="card-title" id="work-order-list-title">Üretim iş emirleri</h2>
        </div>

        <div class="card-body border-bottom admin-list__filters">
            <form method="GET" action="{{ route('admin.work-orders.index') }}" class="row g-3 align-items-end admin-filters" role="search" aria-label="Üretim iş emirlerini filtrele">
                <div class="col-12 col-md-4">
                    <label for="filter-q" class="form-label">Ara</label>
                    <input type="search" id="filter-q" name="q" value="{{ $filters['q'] }}" class="form-control" placeholder="Kod ya da açıklama">
                </div>

                <div class="col-12 col-md-3">
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

                <div class="col-12 col-md-3">
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

                <div class="col-12 col-md-2 admin-filters__actions">
                    <button type="submit" class="btn btn-secondary">
                        <i class="bi bi-funnel" aria-hidden="true"></i> Filtrele
                    </button>
                    @if ($isFiltered)
                        <a href="{{ route('admin.work-orders.index') }}" class="btn btn-link">Filtreyi temizle</a>
                    @endif
                </div>
            </form>
        </div>

        @if ($workOrders->isEmpty())
            <div class="card-body">
                <p class="empty-state mb-0">
                    @if ($isFiltered)
                        Filtreye uyan üretim iş emri yok.
                    @else
                        Henüz üretim iş emri tanımlanmadı.
                    @endif
                </p>
            </div>
        @else
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 admin-list__table">
                        <thead>
                            <tr>
                                <th scope="col">Kod</th>
                                <th scope="col">Açıklama</th>
                                <th scope="col">Bağlantı</th>
                                <th scope="col" class="text-end" title="Üretim iş emrinin seçildiği temizlik kaydı sayısı">Kullanıldığı kayıt</th>
                                <th scope="col"><span class="visually-hidden">İşlemler</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($workOrders as $workOrder)
                                <tr id="work-order-{{ $workOrder->id }}" class="admin-list__row">
                                    <td class="text-nowrap">
                                        <a href="{{ route('admin.work-orders.edit', $workOrder) }}" class="record-no">{{ $workOrder->code }}</a>
                                    </td>
                                    <td>
                                        @if (filled($workOrder->description))
                                            {{ $workOrder->description }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="text-nowrap">@include('admin.work-orders.binding', ['workOrder' => $workOrder])</td>
                                    <td class="text-end">{{ $workOrder->cleanings_count }}</td>
                                    <td class="text-end text-nowrap admin-list__actions">
                                        <a href="{{ route('admin.work-orders.edit', $workOrder) }}" class="btn btn-sm btn-outline-secondary">
                                            <i class="bi bi-pencil" aria-hidden="true"></i> Düzenle
                                            <span class="visually-hidden">{{ $workOrder->code }}</span>
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            @if ($workOrders->hasPages())
                <div class="card-footer admin-list__pagination">
                    {{ $workOrders->links() }}
                </div>
            @endif
        @endif
    </section>
@endsection

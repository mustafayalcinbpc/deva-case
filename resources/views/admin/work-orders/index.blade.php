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
    üretim iş emirlerini ve hattın makinelerine bağlı olanları gösterir. Durum ERP yerine satırdaki
    düğmeyle değişir: satırda yalnızca sıradaki adım (Üretime al / Tamamla) görünür, planlanmış
    emri doğrudan tamamlamak düzenleme ekranındadır; tamamlanma temizlik planlarını tetikler (K-20).
    Liste sade tutulur: açıklama kodun, tamamlanma anı durumun altında; kullanıldığı kayıt sayısı
    düzenleme ekranındadır.
--}}
@section('content')
    @if ($errors->has('status'))
        <div class="alert alert-danger work-order-list__status-error" role="alert">{{ $errors->first('status') }}</div>
    @endif

    <section class="card admin-list work-order-list" aria-labelledby="work-order-list-title">
        <div class="card-header">
            <h2 class="card-title" id="work-order-list-title">Üretim iş emirleri</h2>
        </div>

        <div class="card-body border-bottom admin-list__filters">
            <form method="GET" action="{{ route('admin.work-orders.index') }}" class="row g-3 align-items-end admin-filters" role="search" aria-label="Üretim iş emirlerini filtrele">
                <div class="col-12 col-md-3">
                    <label for="filter-q" class="form-label">Ara</label>
                    <input type="search" id="filter-q" name="q" value="{{ $filters['q'] }}" class="form-control" placeholder="Kod ya da açıklama">
                </div>

                <div class="col-12 col-md-2">
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

                <div class="col-12 col-md-2">
                    <label for="filter-status" class="form-label">Durum</label>
                    <select id="filter-status" name="status" class="form-select">
                        <option value="">Tümü</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}" @selected($filters['status'] === $status)>{{ $status->label() }}</option>
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
                    <table class="table table-hover align-middle mb-0 admin-list__table work-order-list__table">
                        <thead>
                            <tr>
                                <th scope="col">Üretim iş emri</th>
                                <th scope="col">Makine / hat</th>
                                <th scope="col">Durum</th>
                                <th scope="col">Plan</th>
                                <th scope="col"><span class="visually-hidden">İşlemler</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($workOrders as $workOrder)
                                @php
                                    $summary = filled($workOrder->description) ? $workOrder->description : $workOrder->product;
                                @endphp
                                <tr id="work-order-{{ $workOrder->id }}" class="admin-list__row">
                                    <td>
                                        <a href="{{ route('admin.work-orders.edit', $workOrder) }}" class="record-no text-nowrap">{{ $workOrder->code }}</a>
                                        @if (filled($summary))
                                            <span class="cell-sub work-order-list__summary" @if (filled($workOrder->product)) title="Ürün: {{ $workOrder->product }}" @endif>{{ $summary }}</span>
                                        @endif
                                    </td>
                                    <td class="text-nowrap">@include('admin.work-orders.binding', ['workOrder' => $workOrder])</td>
                                    <td class="text-nowrap">
                                        <x-status-badge :status="$workOrder->status" />
                                        @if ($workOrder->completed_at)
                                            <span class="cell-sub work-order-list__completed"><x-datetime :value="$workOrder->completed_at" format="list" /></span>
                                        @endif
                                    </td>
                                    <td class="text-nowrap">@include('admin.work-orders.schedule', ['workOrder' => $workOrder])</td>
                                    <td class="text-end text-nowrap">
                                        <span class="admin-list__actions">
                                            @include('admin.work-orders.status-actions', ['workOrder' => $workOrder, 'size' => 'btn-sm', 'nextOnly' => true])
                                            <a href="{{ route('admin.work-orders.edit', $workOrder) }}" class="btn btn-sm btn-outline-secondary" title="Düzenle">
                                                <i class="bi bi-pencil" aria-hidden="true"></i>
                                                <span class="visually-hidden">{{ $workOrder->code }} düzenle</span>
                                            </a>
                                        </span>
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

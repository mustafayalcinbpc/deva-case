@extends('layouts.app')

@section('title', 'Tesis ve Hatlar')
@section('page-title', 'Tesis ve Hatlar')
@section('page-subtitle', 'Kodlar kayıt numaralarının parçasıdır; tesis ve hat silinmez.')

@section('page-actions')
    <a href="{{ route('admin.facilities.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-circle" aria-hidden="true"></i> Yeni tesis
    </a>
@endsection

{{--
    Tesisler ve hatları, her hattaki makine sayısıyla (R-43). Tesis/hat kodu kayıt numarasında
    yer aldığından (K-17) kayıt açıldıktan sonra değiştirilemez; bu kural düzenleme formundadır.
--}}
@section('content')
    @if ($facilities->isEmpty())
        <section class="card definition-empty">
            <div class="card-body">
                <p class="empty-state mb-0">Henüz tesis tanımlanmamış. Önce bir tesis, sonra hatlarını ekleyin.</p>
            </div>
        </section>
    @endif

    @foreach ($facilities as $facility)
        <section class="card facility-card" aria-labelledby="facility-{{ $facility->id }}-title">
            <div class="card-header">
                <h2 class="card-title" id="facility-{{ $facility->id }}-title">
                    <span class="record-no">{{ $facility->code }}</span> — {{ $facility->name }}
                </h2>
                <div class="card-tools">
                    <a href="{{ route('admin.lines.create', $facility) }}" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-plus" aria-hidden="true"></i> Hat ekle
                    </a>
                    <a href="{{ route('admin.facilities.edit', $facility) }}" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-pencil" aria-hidden="true"></i> Tesisi düzenle
                    </a>
                </div>
            </div>

            @if ($facility->lines->isEmpty())
                <div class="card-body">
                    <p class="empty-state mb-0">Bu tesiste henüz hat yok.</p>
                </div>
            @else
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 line-table">
                            <thead>
                                <tr>
                                    <th scope="col">Hat kodu</th>
                                    <th scope="col">Hat adı</th>
                                    <th scope="col">Makineler</th>
                                    <th scope="col"><span class="visually-hidden">İşlemler</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($facility->lines as $line)
                                    <tr>
                                        <td class="record-no">{{ $line->code }}</td>
                                        <td>{{ $line->name }}</td>
                                        <td class="text-nowrap line-table__machines">
                                            <a href="{{ route('admin.machines.index', ['line_id' => $line->id]) }}">
                                                {{ $line->machines_count }} makine
                                            </a>
                                            @if ($line->machines_count !== $line->active_machines_count)
                                                <span class="text-body-secondary">({{ $line->active_machines_count }} kullanımda)</span>
                                            @endif
                                        </td>
                                        <td class="text-end text-nowrap">
                                            <a href="{{ route('admin.machines.create', ['line_id' => $line->id]) }}" class="btn btn-outline-secondary btn-sm">
                                                <i class="bi bi-plus" aria-hidden="true"></i> Makine ekle
                                            </a>
                                            <a href="{{ route('admin.lines.edit', $line) }}" class="btn btn-outline-secondary btn-sm">
                                                <i class="bi bi-pencil" aria-hidden="true"></i> Düzenle
                                                <span class="visually-hidden">{{ $line->code }}</span>
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </section>
    @endforeach
@endsection

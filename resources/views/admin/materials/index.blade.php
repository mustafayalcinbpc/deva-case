@extends('layouts.app')

@section('title', 'Malzemeler')
@section('page-title', 'Malzemeler')
@section('page-subtitle', 'Temizlikte kullanılan malzeme bu katalogdan seçilir; lot ve son kullanma tarihini operatör girer.')

@section('page-actions')
    <a href="{{ route('admin.materials.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-circle" aria-hidden="true"></i> Yeni malzeme
    </a>
@endsection

{{--
    Malzeme kataloğu (K-13). Kayıtlar malzemeye bağlı olduğu için malzeme silinmez; kullanımdan
    kaldırılır. Kullanımdan kaldırılan malzeme yeni girişlerde seçilemez, geçmiş kayıtlarda görünür.
--}}
@section('content')
    <section class="card admin-list material-list" aria-labelledby="material-list-title">
        <div class="card-header">
            <h2 class="card-title" id="material-list-title">Katalog</h2>
        </div>

        @if ($materials->isEmpty())
            <div class="card-body">
                <p class="empty-state mb-0">Henüz malzeme tanımlanmadı.</p>
            </div>
        @else
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 admin-list__table">
                        <thead>
                            <tr>
                                <th scope="col">Kod</th>
                                <th scope="col">Ad</th>
                                <th scope="col">Durum</th>
                                <th scope="col" class="text-end" title="Malzemenin girildiği temizlik kaydı sayısı">Kullanıldığı kayıt</th>
                                <th scope="col"><span class="visually-hidden">İşlemler</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($materials as $material)
                                <tr id="material-{{ $material->id }}" @class(['admin-list__row', 'admin-list__row--inactive' => ! $material->is_active])>
                                    <td class="text-nowrap">
                                        <a href="{{ route('admin.materials.edit', $material) }}" class="record-no">{{ $material->code }}</a>
                                    </td>
                                    <td>{{ $material->name }}</td>
                                    <td>@include('admin.materials.state', ['material' => $material])</td>
                                    <td class="text-end">{{ $material->cleanings_count }}</td>
                                    <td class="text-end text-nowrap admin-list__actions">
                                        <a href="{{ route('admin.materials.edit', $material) }}" class="btn btn-sm btn-outline-secondary">
                                            <i class="bi bi-pencil" aria-hidden="true"></i> Düzenle
                                            <span class="visually-hidden">{{ $material->code }}</span>
                                        </a>
                                        @include('admin.materials.toggle', ['material' => $material, 'size' => 'btn-sm'])
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            @if ($materials->hasPages())
                <div class="card-footer admin-list__pagination">
                    {{ $materials->links() }}
                </div>
            @endif
        @endif
    </section>
@endsection

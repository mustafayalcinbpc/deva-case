@extends('layouts.app')

@php
    // R-10: kayıtlarda kullanılan lotun numarası değişmez; SKT düzeltilebilir (kayıtlar kopya taşır).
    $lotLocked = $usage > 0;
@endphp

@section('title', "Lot {$lot->lot_no}")
@section('page-title', "Lot: {$material->code} / {$lot->lot_no}")
@section('page-subtitle', 'Son kullanma tarihi lotun özelliğidir; operatör kayıtta yalnızca lotu seçer (K-14).')

@section('page-actions')
    <a href="{{ route('admin.materials.edit', $material) }}#material-lots" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> {{ $material->code }}
    </a>
@endsection

@section('content')
    <div class="row g-3">
        <div class="col-12 col-lg-8">
            <form method="POST"
                  action="{{ route('admin.materials.lots.update', [$material, $lot]) }}"
                  class="card admin-form material-lot-form"
                  data-module="submit-once"
                  aria-labelledby="material-lot-form-title">
                @csrf
                @method('PUT')

                <div class="card-header">
                    <h2 class="card-title" id="material-lot-form-title">Lot bilgileri</h2>
                </div>

                <div class="card-body">
                    @include('admin.materials.lot-fields', ['lot' => $lot, 'lotLocked' => $lotLocked])
                </div>

                <div class="card-footer admin-form__actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check2-circle" aria-hidden="true"></i> Kaydet
                    </button>
                    <a href="{{ route('admin.materials.edit', $material) }}#material-lots" class="btn btn-link">Vazgeç</a>
                </div>
            </form>
        </div>

        <div class="col-12 col-lg-4">
            <section class="card admin-side material-lot-status" aria-labelledby="material-lot-status-title">
                <div class="card-header">
                    <h2 class="card-title" id="material-lot-status-title">Durum</h2>
                </div>
                <div class="card-body">
                    <p>@include('admin.materials.lot-state', ['lot' => $lot])</p>
                    <p class="material-lot-status__usage">
                        @if ($usage === 0)
                            Henüz hiçbir kayıtta kullanılmadı.
                        @else
                            {{ $usage }} temizlik kaydında kullanıldı. Kayıtlar lot numarasını ve SKT'yi seçildikleri andaki haliyle taşır;
                            buradaki düzeltme geçmiş kayıtları değiştirmez.
                        @endif
                    </p>
                    @include('admin.materials.lot-toggle', ['material' => $material, 'lot' => $lot])
                </div>
            </section>
        </div>
    </div>
@endsection

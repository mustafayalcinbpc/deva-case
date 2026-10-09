@extends('layouts.app')

@php
    $editing = $material->exists;
    // R-10: kayıtlarda kullanılan malzemenin kodu değişmez (geçmiş kayıtlar onu koduyla gösterir).
    $codeLocked = $editing && $usage > 0;
@endphp

@section('title', $editing ? "Malzeme {$material->code}" : 'Yeni Malzeme')
@section('page-title', $editing ? "Malzeme: {$material->code}" : 'Yeni Malzeme')
@section('page-subtitle', 'Katalogdaki malzeme, temizlik kaydı açılırken ve sonradan malzeme eklenirken seçilir.')

@section('page-actions')
    <a href="{{ route('admin.materials.index') }}" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Malzemeler
    </a>
@endsection

@section('content')
    <div class="row g-3">
        <div @class(['col-12', 'col-lg-8' => $editing])>
            <form method="POST"
                  action="{{ $editing ? route('admin.materials.update', $material) : route('admin.materials.store') }}"
                  class="card admin-form material-form-card"
                  data-module="submit-once"
                  aria-labelledby="material-form-title">
                @csrf
                @if ($editing)
                    @method('PUT')
                @endif

                <div class="card-header">
                    <h2 class="card-title" id="material-form-title">Malzeme bilgileri</h2>
                </div>

                <div class="card-body">
                    <div class="mb-3">
                        <label for="code" class="form-label">Malzeme kodu</label>
                        <input type="text"
                               id="code"
                               name="code"
                               value="{{ old('code', $material->code) }}"
                               maxlength="50"
                               required
                               autocomplete="off"
                               @readonly($codeLocked)
                               @class(['form-control', 'is-invalid' => $errors->has('code')])
                               aria-describedby="code-help @error('code') code-error @enderror">
                        @error('code')
                            <div id="code-error" class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div id="code-help" class="form-text">
                            @if ($codeLocked)
                                Bu malzeme kayıtlarda kullanıldığı için kodu değiştirilemez; adı düzeltilebilir.
                            @else
                                Katalogda tekildir, ör. DET-01.
                            @endif
                        </div>
                    </div>

                    <div class="mb-0">
                        <label for="name" class="form-label">Malzeme adı</label>
                        <input type="text"
                               id="name"
                               name="name"
                               value="{{ old('name', $material->name) }}"
                               maxlength="255"
                               required
                               @class(['form-control', 'is-invalid' => $errors->has('name')])
                               @error('name') aria-describedby="name-error" @enderror>
                        @error('name')
                            <div id="name-error" class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="card-footer admin-form__actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check2-circle" aria-hidden="true"></i> {{ $editing ? 'Kaydet' : 'Malzemeyi ekle' }}
                    </button>
                    <a href="{{ route('admin.materials.index') }}" class="btn btn-link">Vazgeç</a>
                </div>
            </form>
        </div>

        @if ($editing)
            <div class="col-12 col-lg-4">
                <section class="card admin-side material-status" aria-labelledby="material-status-title">
                    <div class="card-header">
                        <h2 class="card-title" id="material-status-title">Durum</h2>
                    </div>
                    <div class="card-body">
                        <p>@include('admin.materials.state', ['material' => $material])</p>
                        <p class="material-status__usage">
                            @if ($usage === 0)
                                Henüz hiçbir kayıtta kullanılmadı.
                            @else
                                {{ $usage }} temizlik kaydında kullanıldı.
                            @endif
                        </p>
                        <p class="form-text">
                            Malzeme silinmez. Kullanımdan kaldırılan malzeme yeni kayıtlarda ve malzeme eklerken seçilemez;
                            girildiği kayıtlarda görünmeye devam eder.
                        </p>
                        @include('admin.materials.toggle', ['material' => $material])
                    </div>
                </section>

                <x-definition-history :definition="$material" class="material-history mt-3" />
            </div>
        @endif
    </div>
@endsection

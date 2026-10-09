@extends('layouts.app')

@section('title', "{$procedure->code} v{$version->version} · Faz")
@section('page-title', 'Fazı düzenle')
@section('page-subtitle', "{$procedure->code} — v{$version->version} taslağı · {$phase->sequence}. faz")

{{-- Taslak versiyonun bir fazı (R-03, R-06, K-02). Yayımlanmış versiyonun fazı düzenlenemez (K-15). --}}
@section('content')
    @include('admin.procedures.partials.errors', ['keys' => ['version']])

    <form method="POST"
          action="{{ route('admin.procedures.phases.update', [$procedure, $version, $phase]) }}"
          class="procedure-phase-form"
          data-module="submit-once"
          novalidate>
        @csrf
        @method('PUT')

        <section class="card" aria-labelledby="phase-form-title">
            <div class="card-header">
                <h2 class="card-title" id="phase-form-title">{{ $phase->name }}</h2>
            </div>

            <div class="card-body">
                @include('admin.procedures.phases.fields', ['phase' => $phase])
            </div>

            <div class="card-footer procedure-form__actions">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg" aria-hidden="true"></i> Kaydet
                </button>
                <a href="{{ route('admin.procedures.versions.show', [$procedure, $version]) }}#phase-{{ $phase->id }}" class="btn btn-outline-secondary">Vazgeç</a>
            </div>
        </section>
    </form>
@endsection

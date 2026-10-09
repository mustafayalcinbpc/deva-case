@extends('layouts.app')

@php($title = $facility->exists ? "{$facility->code} tesisini düzenle" : 'Yeni tesis')

@section('title', $title)
@section('page-title', $title)
@section('page-subtitle', 'Tesis kodu kayıt numaralarının ve saha defteri referanslarının başında yer alır.')

{{-- Tesis ekleme ve düzenleme (R-43). Kod benzersizdir; kayıt açıldıktan sonra değişmez (K-17). --}}
@section('content')
    <form method="POST"
          action="{{ $facility->exists ? route('admin.facilities.update', $facility) : route('admin.facilities.store') }}"
          class="definition-form"
          data-module="submit-once">
        @csrf
        @if ($facility->exists)
            @method('PUT')
        @endif

        @include('admin.locations.partials.errors')

        <section class="card" aria-labelledby="facility-form-title">
            <div class="card-header">
                <h2 class="card-title" id="facility-form-title">Tesis bilgileri</h2>
            </div>

            <div class="card-body">
                @include('admin.locations.partials.code-field', [
                    'value' => $facility->code,
                    'locked' => $codeLocked,
                    'reason' => \App\Services\Definitions\IssuedCodeLock::FACILITY_REASON,
                    'help' => 'Büyük harf ve rakam, en fazla 10 karakter; bütün tesisler arasında benzersiz.',
                    'example' => 'IST',
                ])

                @include('admin.locations.partials.name-field', ['value' => $facility->name])
            </div>

            <div class="card-footer definition-form__actions">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check2" aria-hidden="true"></i> Kaydet
                </button>
                <a href="{{ route('admin.facilities.index') }}" class="btn btn-outline-secondary">Vazgeç</a>
            </div>
        </section>
    </form>

    @if ($facility->exists)
        <x-definition-history :definition="$facility" class="facility-history mt-3" />
    @endif
@endsection

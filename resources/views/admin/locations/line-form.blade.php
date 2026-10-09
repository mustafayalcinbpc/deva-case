@extends('layouts.app')

@php($title = $line->exists ? "{$facility->code} / {$line->code} hattını düzenle" : "{$facility->code} tesisine hat ekle")

@section('title', $title)
@section('page-title', $title)
@section('page-subtitle', 'Hat kodu kayıt numarasında tesis kodundan sonra gelir.')

{{-- Hat ekleme ve düzenleme (R-43). Kod tesis içinde benzersizdir; hat başka tesise taşınmaz (K-17). --}}
@section('content')
    <form method="POST"
          action="{{ $line->exists ? route('admin.lines.update', $line) : route('admin.lines.store', $facility) }}"
          class="definition-form"
          data-module="submit-once">
        @csrf
        @if ($line->exists)
            @method('PUT')
        @endif

        @include('admin.locations.partials.errors')

        <section class="card" aria-labelledby="line-form-title">
            <div class="card-header">
                <h2 class="card-title" id="line-form-title">Hat bilgileri</h2>
            </div>

            <div class="card-body">
                <div class="mb-3 definition-form__field">
                    <label for="facility" class="form-label">Tesis</label>
                    <input type="text" id="facility" class="form-control" value="{{ $facility->code }} — {{ $facility->name }}" readonly aria-describedby="facility-help">
                    <div id="facility-help" class="form-text">Hat başka bir tesise taşınamaz.</div>
                </div>

                @include('admin.locations.partials.code-field', [
                    'value' => $line->code,
                    'locked' => $codeLocked,
                    'reason' => \App\Services\Definitions\IssuedCodeLock::LINE_REASON,
                    'help' => 'Büyük harf ve rakam, en fazla 10 karakter; tesis içinde benzersiz.',
                    'example' => 'H01',
                ])

                @include('admin.locations.partials.name-field', ['value' => $line->name])
            </div>

            <div class="card-footer definition-form__actions">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check2" aria-hidden="true"></i> Kaydet
                </button>
                <a href="{{ route('admin.facilities.index') }}" class="btn btn-outline-secondary">Vazgeç</a>
            </div>
        </section>
    </form>

    @if ($line->exists)
        <x-definition-history :definition="$line" class="line-history mt-3" />
    @endif
@endsection

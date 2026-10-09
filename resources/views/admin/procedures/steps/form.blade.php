@extends('layouts.app')

@use('App\Http\Requests\Admin\Procedures\ProcedureStepRequest')

@php($editing = $step->exists)

@section('title', "{$procedure->code} v{$version->version} · ".($editing ? 'Adımı düzenle' : 'Adım ekle'))
@section('page-title', $editing ? 'Adımı düzenle' : 'Adım ekle')
@section('page-subtitle', "{$procedure->code} — v{$version->version} taslağı · {$phase->sequence}. faz: {$phase->name}")

{{--
    Taslak versiyonun bir fazındaki adım (R-03, R-04) ve isteğe bağlı fotoğraf ya da video
    (R-05). Dosya `public` diskte procedures/ altında saklanır; yeni dosya eskisinin yerine geçer.
    Yeni adım fazın sonuna eklenir; sırası taslak ekranından değiştirilir.
--}}
@section('content')
    @include('admin.procedures.partials.errors', ['keys' => ['version']])

    <form method="POST"
          action="{{ $editing ? route('admin.procedures.steps.update', [$procedure, $version, $phase, $step]) : route('admin.procedures.steps.store', [$procedure, $version, $phase]) }}"
          enctype="multipart/form-data"
          class="procedure-step-form"
          data-module="submit-once"
          novalidate>
        @csrf
        @if ($editing)
            @method('PUT')
        @endif

        <section class="card" aria-labelledby="step-form-title">
            <div class="card-header">
                <h2 class="card-title" id="step-form-title">{{ $editing ? "{$step->sequence}. adım" : 'Yeni adım' }}</h2>
            </div>

            <div class="card-body">
                <div class="mb-3">
                    <label for="step-title" class="form-label">Başlık</label>
                    <input type="text"
                           id="step-title"
                           name="title"
                           value="{{ old('title', $step->title) }}"
                           maxlength="255"
                           required
                           @class(['form-control', 'is-invalid' => $errors->has('title')])
                           @error('title') aria-describedby="step-title-error" @enderror>
                    @error('title')
                        <div id="step-title-error" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="step-description" class="form-label">Açıklama <span class="optional-mark">(isteğe bağlı)</span></label>
                    <textarea id="step-description"
                              name="description"
                              rows="4"
                              maxlength="5000"
                              @class(['form-control', 'is-invalid' => $errors->has('description')])
                              aria-describedby="step-description-help @error('description') step-description-error @enderror">{{ old('description', $step->description) }}</textarea>
                    @error('description')
                        <div id="step-description-error" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                    <div id="step-description-help" class="form-text">Operatörün sahada göreceği talimat (R-04).</div>
                </div>

                @if ($editing && $step->media_path)
                    <div class="mb-3 procedure-step-form__current-media">
                        <p class="form-label mb-2">Mevcut medya</p>
                        @include('admin.procedures.partials.media', ['step' => $step, 'preview' => true])
                        <div class="form-check mt-2">
                            <input type="checkbox" id="step-remove-media" name="remove_media" value="1" class="form-check-input" @checked(old('remove_media'))>
                            <label for="step-remove-media" class="form-check-label">Medyayı kaldır</label>
                        </div>
                    </div>
                @endif

                <div class="mb-0">
                    <label for="step-media" class="form-label">
                        {{ $editing && $step->media_path ? 'Yeni fotoğraf ya da video' : 'Fotoğraf ya da video' }}
                        <span class="optional-mark">(isteğe bağlı)</span>
                    </label>
                    <input type="file"
                           id="step-media"
                           name="media"
                           accept="{{ collect(ProcedureStepRequest::MEDIA_EXTENSIONS)->map(fn ($extension) => ".{$extension}")->implode(',') }},image/jpeg,image/png,image/webp,video/mp4,video/webm"
                           @class(['form-control', 'is-invalid' => $errors->has('media')])
                           aria-describedby="step-media-help @error('media') step-media-error @enderror">
                    @error('media')
                        <div id="step-media-error" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                    <div id="step-media-help" class="form-text">
                        JPG, PNG ya da WEBP görsel veya MP4 ya da WEBM video; en fazla {{ ProcedureStepRequest::MEDIA_MAX_KB / 1024 }} MB.
                        Yeni kayıtlarda adımın yanında gösterilir (R-05).@if ($editing && $step->media_path) Yeni dosya mevcut medyanın yerine geçer.@endif
                    </div>
                </div>
            </div>

            <div class="card-footer procedure-form__actions">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg" aria-hidden="true"></i> {{ $editing ? 'Kaydet' : 'Adımı ekle' }}
                </button>
                <a href="{{ route('admin.procedures.versions.show', [$procedure, $version]) }}#{{ $editing ? "step-{$step->id}" : "phase-{$phase->id}" }}" class="btn btn-outline-secondary">Vazgeç</a>
            </div>
        </section>
    </form>
@endsection

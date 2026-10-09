@extends('layouts.app')

@php($editing = $procedure->exists)

@section('title', $editing ? "{$procedure->code} · Düzenle" : 'Yeni Prosedür')
@section('page-title', $editing ? 'Prosedürü düzenle' : 'Yeni prosedür')
@section('page-subtitle', $editing
    ? "{$procedure->code} — {$procedure->name}"
    : 'Prosedür boş bir v1 taslağıyla oluşturulur; fazları ve adımları taslakta tanımlayıp yayımlarsınız.')

{{-- Prosedürün kodu ve adı. Fazlar ve adımlar versiyonda tanımlanır (K-15). --}}
@section('content')
    <form method="POST"
          action="{{ $editing ? route('admin.procedures.update', $procedure) : route('admin.procedures.store') }}"
          class="procedure-form"
          data-module="submit-once"
          novalidate>
        @csrf
        @if ($editing)
            @method('PUT')
        @endif

        <section class="card" aria-labelledby="procedure-form-title">
            <div class="card-header">
                <h2 class="card-title" id="procedure-form-title">Prosedür bilgileri</h2>
            </div>

            <div class="card-body">
                <div class="mb-3">
                    <label for="code" class="form-label">Kod</label>
                    <input type="text"
                           id="code"
                           name="code"
                           value="{{ old('code', $procedure->code) }}"
                           maxlength="{{ \App\Http\Requests\Admin\Procedures\ProcedureRequest::CODE_MAX }}"
                           autocapitalize="characters"
                           autocomplete="off"
                           required
                           @class(['form-control', 'is-invalid' => $errors->has('code')])
                           aria-describedby="code-help @error('code') code-error @enderror">
                    @error('code')
                        <div id="code-error" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                    <div id="code-help" class="form-text">Benzersiz; büyük harf, rakam ve tire. Ör. PRC-DOL.</div>
                </div>

                <div class="mb-0">
                    <label for="name" class="form-label">Ad</label>
                    <input type="text"
                           id="name"
                           name="name"
                           value="{{ old('name', $procedure->name) }}"
                           maxlength="255"
                           required
                           @class(['form-control', 'is-invalid' => $errors->has('name')])
                           @error('name') aria-describedby="name-error" @enderror>
                    @error('name')
                        <div id="name-error" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="card-footer procedure-form__actions">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg" aria-hidden="true"></i> {{ $editing ? 'Kaydet' : 'Prosedürü oluştur' }}
                </button>
                <a href="{{ $editing ? route('admin.procedures.show', $procedure) : route('admin.procedures.index') }}" class="btn btn-outline-secondary">Vazgeç</a>
            </div>
        </section>
    </form>
@endsection

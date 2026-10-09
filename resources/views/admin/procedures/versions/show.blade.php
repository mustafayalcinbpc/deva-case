@extends('layouts.app')

@php($editable = $version->isDraft())

@section('title', "{$procedure->code} v{$version->version}")
@section('page-title', "{$procedure->code} — v{$version->version}")

@section('page-subtitle')
    {{ $procedure->name }} · <x-status-badge :status="$status" />
@endsection

@section('page-actions')
    <a href="{{ route('admin.procedures.show', $procedure) }}" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Prosedüre dön
    </a>
    @if ($editable)
        <form method="POST"
              action="{{ route('admin.procedures.versions.destroy', [$procedure, $version]) }}"
              class="d-inline"
              data-module="confirm-submit submit-once"
              data-confirm="v{{ $version->version }} taslağı fazları ve adımlarıyla birlikte silinsin mi?">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-outline-secondary">
                <i class="bi bi-trash" aria-hidden="true"></i> Taslağı sil
            </button>
        </form>
    @elseif ($procedure->draftVersion === null)
        <form method="POST" action="{{ route('admin.procedures.versions.store', $procedure) }}" class="d-inline" data-module="submit-once">
            @csrf
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-files" aria-hidden="true"></i> Yeni taslak
            </button>
        </form>
    @endif
@endsection

{{--
    Taslak düzenleyici ya da yayımlanmış versiyonun salt okunur görünümü (K-15). Taslakta
    fazlar ve adımlar eklenir, düzenlenir, silinir ve sıralanır; bütün işlemler sunucu tarafı
    formlardır, JS gerektirmez. Yayımlanmış versiyon hiçbir zaman değişmez.
--}}
@section('content')
    @include('admin.procedures.partials.errors', ['keys' => ['version']])

    @if ($editable)
        <div class="alert alert-info procedure-version-note" role="note">
            Bu bir taslak: yayımlanana kadar hiçbir kayıtta kullanılmaz. Yayımlandıktan sonra değiştirilemez;
            sonraki değişiklikler yeni bir taslakla yapılır (K-15).
        </div>
    @else
        <div class="alert alert-info procedure-version-note" role="note">
            Bu versiyon yayımlandı ve değiştirilemez. Bu versiyonla açılan kayıtlar her zaman bu tanımla görüntülenir (R-13, K-15).
            @if ($procedure->draftVersion)
                Değişiklikler <a href="{{ route('admin.procedures.versions.show', [$procedure, $procedure->draftVersion]) }}" class="alert-link">v{{ $procedure->draftVersion->version }} taslağında</a> yapılır.
            @endif
        </div>
    @endif

    <div class="row g-3">
        <div class="col-xl-8 procedure-version__phases">
            @forelse ($version->phases as $phase)
                @include('admin.procedures.versions.phase', ['phase' => $phase, 'first' => $loop->first, 'last' => $loop->last])
            @empty
                <section class="card">
                    <div class="card-body">
                        <p class="empty-state mb-0">
                            Bu versiyonda faz yok.@if ($editable) Sağdaki formla ilk fazı ekleyin; her faz sıralı adımlardan oluşur (R-03).@endif
                        </p>
                    </div>
                </section>
            @endforelse
        </div>

        <div class="col-xl-4 procedure-version__side">
            @include('admin.procedures.versions.settings')

            @if ($editable)
                @include('admin.procedures.versions.add-phase')
                @include('admin.procedures.versions.publish')
            @endif
        </div>
    </div>
@endsection

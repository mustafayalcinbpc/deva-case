@extends('layouts.app')

@section('title', $cleaning->record_no)

@section('page-title')
    <span>Temizlik kaydı <span class="record-no">{{ $cleaning->record_no }}</span></span>
    <x-status-badge :status="$cleaning->status" class="cleaning-detail__status" />
@endsection

@section('page-subtitle')
    <span class="cleaning-detail__location" title="{{ $cleaning->facility->name }} / {{ $cleaning->line->name }} / {{ $cleaning->machine->name }}">{{ $cleaning->facility->code }} / {{ $cleaning->line->code }} / {{ $cleaning->machine->code }}</span>
    · <span class="cleaning-detail__machine">{{ $cleaning->machine->name }}</span>
    · <span class="cleaning-detail__type">{{ $cleaning->type->label() }}</span>
@endsection

@section('page-actions')
    <a href="{{ route('cleanings.index') }}" class="btn btn-secondary cleaning-detail__back">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Kayıt listesi
    </a>
    @can('view-reports')
        {{-- R-45: kaydın bütün hikâyesi ve olay zincirinin doğrulaması (yazdırılabilir / PDF). --}}
        <a href="{{ route('reports.audit', $cleaning) }}" class="btn btn-secondary cleaning-detail__audit">
            <i class="bi bi-file-earmark-text" aria-hidden="true"></i> Denetim raporu
        </a>
        <form method="POST" action="{{ route('reports.audit.pdf', $cleaning) }}" class="d-inline" data-module="submit-once">
            @csrf
            <button type="submit" class="btn btn-secondary cleaning-detail__audit-pdf">
                <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> PDF hazırla
            </button>
        </form>
    @endcan
@endsection

{{--
    Kayıt detayı: sahada adım adım ilerlenen ekran (R-21–R-25). En üstte güncel adım ve onun
    aksiyonları ("Şimdi"), ardından kontrol listesi; yan sütunda özet, malzemeler ve iptal; en
    altta olay geçmişi. Formlar yalnızca kayıt açıksa ve CleaningPermissions izin veriyorsa
    gösterilir; asıl kontrol her zaman workflow'dadır.
--}}
@section('content')
    <div class="cleaning-detail">
        {{-- Bölümler arası atlama: sekme görünümünde bağlantılar. Son atlanan bölüm CSS :target ile vurgulanır. --}}
        <nav class="cleaning-detail__nav" aria-label="Sayfa bölümleri">
            <ul class="nav nav-tabs">
                <li class="nav-item"><a class="nav-link cleaning-detail__tab--now" href="#now">Şimdi</a></li>
                <li class="nav-item"><a class="nav-link cleaning-detail__tab--checklist" href="#checklist">Adımlar</a></li>
                <li class="nav-item"><a class="nav-link cleaning-detail__tab--summary" href="#summary">Özet</a></li>
                <li class="nav-item"><a class="nav-link cleaning-detail__tab--materials" href="#materials">Malzemeler</a></li>
                <li class="nav-item"><a class="nav-link cleaning-detail__tab--history" href="#history">Olay geçmişi</a></li>
            </ul>
        </nav>

        <div class="cleaning-detail__layout">
            <div class="cleaning-detail__main">
                @include('cleanings.show.now')
                @include('cleanings.show.checklist')
            </div>

            <div class="cleaning-detail__aside">
                @include('cleanings.show.summary')
                @include('cleanings.show.materials')
                @if ($cancelReasons !== [])
                    @include('cleanings.show.cancel')
                @endif
            </div>
        </div>

        @include('cleanings.show.history')
    </div>
@endsection

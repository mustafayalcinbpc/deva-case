@extends('layouts.app')

@section('title', $cleaning->record_no)

@section('page-title')
    Temizlik kaydı <span class="record-no">{{ $cleaning->record_no }}</span>
@endsection

{{--
    Kayıt detayı: sahada adım adım ilerlenen ekran (R-21–R-25). En üstte güncel adım ve onun
    aksiyonları ("Şimdi"), ardından kontrol listesi; yan sütunda özet, malzemeler ve iptal; en
    altta olay geçmişi. Formlar yalnızca kayıt açıksa ve CleaningPermissions izin veriyorsa
    gösterilir; asıl kontrol her zaman workflow'dadır.
--}}
@section('content')
    <div class="cleaning-detail">
        <div class="cleaning-detail__header">
            <x-status-badge :status="$cleaning->status" class="cleaning-detail__status" />
            <span class="cleaning-detail__location">{{ $cleaning->facility->code }} / {{ $cleaning->line->code }} / {{ $cleaning->machine->code }}</span>
            <span class="cleaning-detail__type">{{ $cleaning->type->label() }}</span>
            <a href="{{ route('cleanings.index') }}" class="cleaning-detail__back">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> Kayıt listesi
            </a>
        </div>

        <nav class="cleaning-detail__nav" aria-label="Sayfa bölümleri">
            <ul class="nav nav-pills">
                <li class="nav-item"><a class="nav-link" href="#now">Şimdi</a></li>
                <li class="nav-item"><a class="nav-link" href="#checklist">Adımlar</a></li>
                <li class="nav-item"><a class="nav-link" href="#summary">Özet</a></li>
                <li class="nav-item"><a class="nav-link" href="#materials">Malzemeler</a></li>
                <li class="nav-item"><a class="nav-link" href="#history">Olay geçmişi</a></li>
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

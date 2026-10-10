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
    Kayıt detayı: sahada adım adım ilerlenen ekran (R-21–R-25). Sol sütunda sekmeler: güncel adım
    ve aksiyonları ("Şimdi"), kontrol listesi, özet, malzemeler, olay geçmişi; her sekmede yalnızca
    kendi bölümü görünür. Sağ sütunda fazların ve adımların ilerlemesi ile iptal. Formlar yalnızca
    kayıt açıksa ve CleaningPermissions izin veriyorsa gösterilir; asıl kontrol her zaman workflow'dadır.

    Bölüm id'leri (#now, #checklist, #summary, #materials, #history) adres çapası olarak kullanılır;
    çapa bir paneldeyse o sekme açılır (resources/js/modules/section-tabs.js).
--}}
@php
    $tabs = [
        'now' => 'Şimdi',
        'checklist' => 'Adımlar',
        'summary' => 'Özet',
        'materials' => 'Malzemeler',
        'history' => 'Olay geçmişi',
    ];
@endphp

@section('content')
    <div class="cleaning-detail">
        <div class="cleaning-detail__layout">
            <div class="cleaning-detail__main" data-module="section-tabs">
                <x-section-tabs.nav :tabs="$tabs" label="Kayıt bölümleri" class="cleaning-detail__nav" />

                <div class="tab-content section-tabs__panes cleaning-detail__panes">
                    @foreach (array_keys($tabs) as $section)
                        <x-section-tabs.pane :section="$section" :active="$loop->first" class="cleaning-detail__pane">
                            @include('cleanings.show.'.$section)
                        </x-section-tabs.pane>
                    @endforeach
                </div>
            </div>

            <aside class="cleaning-detail__aside" aria-label="İlerleme ve iptal">
                @include('cleanings.show.progress')
                @if ($cancelReasons !== [])
                    @include('cleanings.show.cancel')
                @endif
            </aside>
        </div>
    </div>
@endsection

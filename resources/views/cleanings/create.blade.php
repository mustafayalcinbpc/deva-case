@extends('layouts.app')

@section('title', 'Yeni Kayıt')
@section('page-title', 'Yeni Temizlik Kaydı')

{{--
    Kayıt açma formu (R-14–R-20). JS olmadan da çalışır; cleaning-form.js yalnızca seçilen
    makinenin özetini gösterir, iş emirlerini makineye göre süzer ve malzeme satırı ekler/çıkarır.
--}}
@section('content')
    <form method="POST" action="{{ route('cleanings.store') }}" class="cleaning-form" data-module="cleaning-form">
        @csrf

        @include('cleanings.form.errors')

        <p class="cleaning-form__intro">
            Kayıt açmak temizliği başlatmaz: süre, ilk adım başlatıldığında başlar.
            Kaydın sorumlusu sizsiniz; sorumluluk sonradan başkasına devredilemez.
        </p>

        @include('cleanings.form.machine')
        @include('cleanings.form.type')
        @include('cleanings.form.helpers')
        @include('cleanings.form.materials')
        @include('cleanings.form.details')

        <div class="cleaning-form__actions">
            <button type="submit" class="btn btn-primary btn-lg cleaning-form__submit">
                <i class="bi bi-check2-circle" aria-hidden="true"></i> Kaydı aç
            </button>
            <a href="{{ route('cleanings.index') }}" class="btn btn-link">Vazgeç</a>
        </div>
    </form>
@endsection

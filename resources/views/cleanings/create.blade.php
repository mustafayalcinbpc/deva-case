@extends('layouts.app')

@section('title', 'Yeni Kayıt')
@section('page-title', 'Yeni Temizlik Kaydı')
@section('page-subtitle', 'Kayıt açmak temizliği başlatmaz: süre, ilk adım başlatıldığında başlar.')

@section('page-actions')
    <a href="{{ route('cleanings.index') }}" class="btn btn-secondary">Vazgeç</a>
    <button type="submit" form="cleaning-form" class="btn btn-primary cleaning-form__submit-top">
        <i class="bi bi-check2-circle" aria-hidden="true"></i> Kaydı aç
    </button>
@endsection

{{--
    Kayıt açma formu (R-14–R-20). JS olmadan da çalışır; cleaning-form.js yalnızca seçilen
    makinenin özetini gösterir, üretim iş emirlerini makineye göre süzer ve malzeme satırı ekler/çıkarır.
    Başlıktaki "Kaydı aç" düğmesi form="cleaning-form" ile aynı formu gönderir; telefonda ve
    uzun formda sağ sütundaki (dar ekranda en alttaki) düğme kullanılır.

    Görevden gelindiğinde (?task=ID, K-21) $task doludur: makine ve tür (planlı) görevden gelir ve
    değiştirilemez, üretim iş emri görevin sonraki emriyle dolar; görev gizli alanla gönderilir.
    İstenen görev artık açık değilse (kayıt açılmış, iptal edilmiş) ya da müdahale vakti henüz
    gelmediyse (K-24, $upcomingTask) uyarı gösterilir ve form görevsiz açılır.
--}}
@section('content')
    <form method="POST" action="{{ route('cleanings.store') }}" id="cleaning-form" class="cleaning-form" data-module="cleaning-form">
        @csrf

        @include('cleanings.form.errors')

        @if ($task)
            @include('cleanings.form.task')
        @elseif ($upcomingTask)
            <div class="alert alert-info cleaning-form__task-upcoming" role="alert">
                {{ $upcomingTask->machine->code }} makinesindeki temizliğin vakti henüz gelmedi:
                @if ($upcomingTask->scheduled_at)
                    görevden kayıt <x-datetime :value="$upcomingTask->scheduled_at" format="list" /> sonrasında açılabilir.
                @else
                    görevden kayıt {{ $upcomingTask->triggerWorkOrder?->code ?? 'üretim iş emri' }} tamamlanınca açılabilir.
                @endif
                <a href="{{ route('dashboard') }}" class="alert-link">Yapılması gereken temizliklere dön</a>
            </div>
        @elseif (request()->filled('task'))
            <div class="alert alert-warning cleaning-form__task-gone" role="alert">
                Bu görev artık açık değil: görevden kayıt açılmış ya da görev iptal edilmiş olabilir.
                <a href="{{ route('dashboard') }}" class="alert-link">Yapılması gereken temizliklere dön</a>
            </div>
        @endif

        {{-- Geniş ekranda iki sütun: solda zorunlu bölümler, sağda üretim iş emri ve gönder. --}}
        <div class="cleaning-form__layout">
            <div class="cleaning-form__main">
                @include('cleanings.form.machine')
                @include('cleanings.form.type')
                @include('cleanings.form.helpers')
                @include('cleanings.form.materials')
            </div>

            <div class="cleaning-form__aside">
                @include('cleanings.form.details')

                <div class="cleaning-form__actions">
                    <button type="submit" class="btn btn-primary btn-lg cleaning-form__submit">
                        <i class="bi bi-check2-circle" aria-hidden="true"></i> Kaydı aç
                    </button>
                    <a href="{{ route('cleanings.index') }}" class="btn btn-link">Vazgeç</a>
                </div>
            </div>
        </div>
    </form>
@endsection

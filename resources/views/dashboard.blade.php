@extends('layouts.app')

@section('title', 'Gösterge Paneli')
@section('page-title', 'Gösterge Paneli')

@section('content')
    <section class="dashboard-stats row" aria-label="Özet">
        @include('dashboard.stat', [
            'modifier' => 'created',
            'icon' => 'bi-hourglass-split',
            'label' => 'Başlamamış kayıt',
            'value' => $stats['created'],
            'hint' => 'İlk adımı bekleniyor',
        ])
        @include('dashboard.stat', [
            'modifier' => 'in-progress',
            'icon' => 'bi-play-circle',
            'label' => 'Devam eden kayıt',
            'value' => $stats['in_progress'],
            'hint' => 'İlk adımı başlamış, açık',
        ])
        @include('dashboard.stat', [
            'modifier' => 'completed-today',
            'icon' => 'bi-check2-circle',
            'label' => 'Bugün tamamlanan',
            'value' => $stats['completed_today'],
            'hint' => $today->format('d.m.Y'),
        ])
        @include('dashboard.stat', [
            'modifier' => 'below-minimum',
            'icon' => 'bi-exclamation-triangle',
            'label' => 'Minimum süre altı faz',
            'value' => $stats['below_minimum'],
            'hint' => "Son {$belowMinimumDays} gün",
        ])
    </section>

    @include('dashboard.open-cleanings', ['rows' => $rows])
@endsection

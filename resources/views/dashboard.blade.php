@extends('layouts.app')

@section('title', 'Gösterge Paneli')
@section('page-title', 'Gösterge Paneli')

@section('page-subtitle')
    {{ $today->locale('tr')->translatedFormat('j F Y, l') }} · Hoş geldiniz, {{ auth()->user()->name }}
@endsection

@section('page-actions')
    <a href="{{ route('cleanings.index') }}" class="btn btn-secondary">
        <i class="bi bi-list-check" aria-hidden="true"></i> Tüm kayıtlar
    </a>
    <a href="{{ route('cleanings.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-lg" aria-hidden="true"></i> Yeni kayıt
    </a>
@endsection

@section('content')
    <section class="dashboard-stats row" aria-label="Özet">
        @include('dashboard.stat', [
            'modifier' => 'created',
            'icon' => 'bi-hourglass-split',
            'label' => 'Başlamamış kayıt',
            'value' => $stats['created'],
            'unit' => 'kayıt',
            'hint' => 'İlk adımı bekleniyor',
        ])
        @include('dashboard.stat', [
            'modifier' => 'in-progress',
            'icon' => 'bi-play-circle',
            'label' => 'Devam eden kayıt',
            'value' => $stats['in_progress'],
            'unit' => 'kayıt',
            'hint' => 'İlk adımı başlamış, açık',
        ])
        @include('dashboard.stat', [
            'modifier' => 'completed-today',
            'icon' => 'bi-check2-circle',
            'label' => 'Bugün tamamlanan',
            'value' => $stats['completed_today'],
            'unit' => 'kayıt',
            'hint' => $today->format('d.m.Y'),
        ])
        @include('dashboard.stat', [
            'modifier' => 'below-minimum',
            'icon' => 'bi-exclamation-triangle',
            'label' => 'Minimum süre altı faz',
            'value' => $stats['below_minimum'],
            'unit' => 'faz',
            'hint' => "Son {$belowMinimumDays} gün",
        ])
    </section>

    @include('dashboard.tasks', ['tasks' => $tasks, 'now' => $now])

    @include('dashboard.open-cleanings', ['rows' => $rows])
@endsection

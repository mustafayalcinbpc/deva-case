@extends('layouts.app')

@section('title', 'Bildirimler')
@section('page-title', 'Bildirimler')
@section('page-subtitle', 'Size gönderilen bildirimler, en yeni önce')

@section('page-actions')
    <form method="POST" action="{{ route('notifications.read-all') }}" class="notification-list__read-all">
        @csrf
        <button type="submit" class="btn btn-outline-secondary">
            <i class="bi bi-check2-all" aria-hidden="true"></i> Tümünü okundu işaretle
        </button>
    </form>
@endsection

@section('content')
    <section class="card notification-list" aria-label="Bildirimler">
        <div class="card-header">
            <ul class="nav nav-tabs card-header-tabs notification-list__filters">
                @foreach (['' => 'Tümü'] + $filters as $value => $label)
                    @php($active = $filter === ($value === '' ? null : $value))
                    <li class="nav-item">
                        <a href="{{ route('notifications.index', $value === '' ? [] : ['filter' => $value]) }}" @class(['nav-link', 'active' => $active]) @if ($active) aria-current="page" @endif>{{ $label }}</a>
                    </li>
                @endforeach
            </ul>
        </div>

        @if ($notifications->isEmpty())
            <div class="card-body">
                <p class="empty-state mb-0">{{ match ($filter) {
                    'unread' => 'Okunmamış bildirim yok.',
                    'read' => 'Okunmuş bildirim yok.',
                    default => 'Henüz bildirim yok.',
                } }}</p>
            </div>
        @else
            <div class="list-group list-group-flush notification-list__items">
                @foreach ($notifications as $notification)
                    @include('notifications.partials.item', ['notification' => $notification, 'class' => 'list-group-item list-group-item-action'])
                @endforeach
            </div>

            @if ($notifications->hasPages())
                <div class="card-footer notification-list__pagination">
                    {{ $notifications->links() }}
                </div>
            @endif
        @endif
    </section>
@endsection

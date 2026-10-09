{{--
    Bildirim zili (üst bar). Sayfa başına iki sorgu: okunmamış sayısı ve son beş bildirim;
    bildirimlerin ilişkisi olmadığından N+1 yoktur. Görünüm tema katmanında: notification-bell,
    notification-bell__toggle, __count, __menu, __header, __empty, __footer, __read-all, __all
    ve notification-item* (notifications/partials/item).
--}}
@auth
    @php
        $user = auth()->user();
        $unreadCount = $user->unreadNotifications()->count();
        $latest = $user->notifications()->limit(5)->get();
    @endphp

    <div class="dropdown notification-bell">
        <a href="#" class="nav-link notification-bell__toggle" data-bs-toggle="dropdown" aria-expanded="false"
           aria-label="Bildirimler{{ $unreadCount > 0 ? " ({$unreadCount} okunmamış)" : '' }}">
            <i class="bi bi-bell" aria-hidden="true"></i>
            @if ($unreadCount > 0)
                <span class="badge navbar-badge notification-bell__count">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
            @endif
        </a>

        <div class="dropdown-menu dropdown-menu-end dropdown-menu-lg notification-bell__menu">
            <div class="dropdown-header notification-bell__header">
                {{ $unreadCount > 0 ? "{$unreadCount} okunmamış bildirim" : 'Okunmamış bildirim yok' }}
            </div>
            <div class="dropdown-divider"></div>

            @forelse ($latest as $notification)
                @include('notifications.partials.item', ['notification' => $notification, 'class' => 'dropdown-item'])
            @empty
                <p class="dropdown-item-text notification-bell__empty mb-0">Henüz bildirim yok.</p>
            @endforelse

            <div class="dropdown-divider"></div>
            <div class="notification-bell__footer">
                @if ($unreadCount > 0)
                    <form method="POST" action="{{ route('notifications.read-all') }}" class="notification-bell__read-all">
                        @csrf
                        <button type="submit" class="dropdown-item">
                            <i class="bi bi-check2-all" aria-hidden="true"></i> Tümünü okundu işaretle
                        </button>
                    </form>
                @endif
                <a href="{{ route('notifications.index') }}" class="dropdown-item notification-bell__all">
                    <i class="bi bi-list-ul" aria-hidden="true"></i> Tüm bildirimler
                </a>
            </div>
        </div>
    </div>
@endauth

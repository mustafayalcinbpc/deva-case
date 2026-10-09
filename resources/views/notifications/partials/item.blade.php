{{--
    Tek bildirim satırı (zil ve liste ortak). `data`: title, message, url, level
    (docs/plan-yonetim-rapor-tasarim.md). Görünüm tema katmanında: notification-item,
    notification-item--{info|warning|danger}, notification-item--unread.
--}}
@php
    $data = $notification->data;
    $level = in_array($data['level'] ?? null, ['info', 'warning', 'danger'], true) ? $data['level'] : 'info';
    [$icon, $levelLabel] = match ($level) {
        'warning' => ['bi-exclamation-triangle', 'Uyarı'],
        'danger' => ['bi-x-octagon', 'Önemli'],
        default => ['bi-info-circle', 'Bilgi'],
    };
    $unread = $notification->read_at === null;
@endphp

<a href="{{ route('notifications.open', $notification) }}" @class([$class ?? '', 'notification-item', "notification-item--{$level}", 'notification-item--unread' => $unread])>
    <i class="bi {{ $icon }} notification-item__icon" role="img" aria-label="{{ $levelLabel }}"></i>
    <span class="notification-item__body">
        <span class="notification-item__title">{{ $data['title'] ?? 'Bildirim' }}</span>
        @if (filled($data['message'] ?? null))
            <span class="notification-item__message">{{ $data['message'] }}</span>
        @endif
        <time class="notification-item__time" datetime="{{ $notification->created_at->toIso8601String() }}" title="{{ $notification->created_at->copy()->setTimezone(config('app.display_timezone'))->format('d.m.Y H:i') }}">{{ $notification->created_at->diffForHumans() }}</time>
    </span>
    @if ($unread)
        <span class="visually-hidden">(okunmadı)</span>
    @endif
</a>

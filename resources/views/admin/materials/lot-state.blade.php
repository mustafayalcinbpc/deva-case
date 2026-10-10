{{--
    Lotun durumu (K-14): kullanımdan kaldırılmış ya da SKT'si geçmiş lot yeni girişte seçilemez.
    Renkler tema katmanında (status-badge--active / --retired / --expired).
--}}
@if (! $lot->is_active)
    <span class="status-badge status-badge--retired">Kullanımdan kaldırıldı</span>
@elseif ($lot->isExpiredOn(now()))
    <span class="status-badge status-badge--expired">SKT geçti</span>
@else
    <span class="status-badge status-badge--active">Kullanımda</span>
@endif

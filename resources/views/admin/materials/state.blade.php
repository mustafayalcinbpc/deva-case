{{-- Malzemenin kullanım durumu (K-13). Renkler tema katmanında: status-badge--active / --retired (makinelerle aynı). --}}
@if ($material->is_active)
    <span class="status-badge status-badge--active">Kullanımda</span>
@else
    <span class="status-badge status-badge--retired">Kullanımdan kaldırıldı</span>
@endif

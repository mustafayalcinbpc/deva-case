{{-- Kullanıcının durumu. Renkler tema katmanında: status-badge--active / --inactive. --}}
@if ($user->is_active)
    <span class="status-badge status-badge--active">Aktif</span>
@else
    <span class="status-badge status-badge--inactive">Pasif</span>
@endif

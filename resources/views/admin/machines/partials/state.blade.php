{{-- Makinenin kullanım durumu (K-16). Renkler tema katmanında: status-badge--active / --retired. --}}
@if ($machine->is_active)
    <span class="status-badge status-badge--active">Kullanımda</span>
@else
    <span class="status-badge status-badge--retired">Kullanımdan kaldırıldı</span>
@endif

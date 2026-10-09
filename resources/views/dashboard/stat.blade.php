{{-- Özet sayacı (AdminLTE info-box). Görünüm tema katmanında: .dashboard-stat, .dashboard-stat--{modifier}. --}}
<div class="col-12 col-sm-6 col-xl-3">
    <div class="info-box dashboard-stat dashboard-stat--{{ $modifier }}">
        <span class="info-box-icon" aria-hidden="true"><i class="bi {{ $icon }}"></i></span>
        <div class="info-box-content">
            <span class="info-box-text">{{ $label }}</span>
            <span class="info-box-number">{{ $value }}</span>
            <span class="dashboard-stat__hint small text-body-secondary">{{ $hint }}</span>
        </div>
    </div>
</div>

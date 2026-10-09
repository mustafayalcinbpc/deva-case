{{--
    Özet sayacı: Nocturne KPI kartı (kicker, büyük sayı, birim, alt bilgi). Sağ üstteki simge
    ilgili durumun rengini taşır: .dashboard-stat--{modifier} (theme/pages/_dashboard.scss).
--}}
<div class="col-6 col-xl-3">
    <div class="card kpi-card dashboard-stat dashboard-stat--{{ $modifier }}">
        <div class="kpi-card__head">
            <p class="card-kicker">{{ $label }}</p>
            <span class="dashboard-stat__icon" aria-hidden="true"><i class="bi {{ $icon }}"></i></span>
        </div>
        <p class="kpi-card__value">
            <span class="kpi-num info-box-number">{{ $value }}</span>
            <span class="kpi-card__unit">{{ $unit }}</span>
        </p>
        <p class="kpi-card__meta dashboard-stat__hint">{{ $hint }}</p>
    </div>
</div>

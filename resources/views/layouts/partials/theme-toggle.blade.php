{{-- Açık/koyu tema düğmesi. Simge temaya göre CSS ile seçilir; etiketi theme-toggle.js günceller. --}}
<button type="button" class="{{ $class ?? 'nav-link' }} theme-toggle" data-module="theme-toggle" aria-label="Koyu tema" title="Koyu tema">
    <i class="bi bi-moon theme-toggle__icon theme-toggle__icon--dark" aria-hidden="true"></i>
    <i class="bi bi-sun theme-toggle__icon theme-toggle__icon--light" aria-hidden="true"></i>
</button>

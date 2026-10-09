{{-- Marka bloğu: vurgu renginde kare içinde baş harf, ad ve alt satır. $link: false ise bağlantı olmaz. --}}
@php($brandTag = ($link ?? true) ? 'a' : 'div')
<{{ $brandTag }} @if ($brandTag === 'a') href="{{ route('dashboard') }}" @endif class="app-brand">
    <span class="app-brand__mark" aria-hidden="true">D</span>
    <span class="app-brand__text">
        <span class="app-brand__name">Dijital Temizlik</span>
        <span class="app-brand__tagline">Takip Sistemi</span>
    </span>
</{{ $brandTag }}>

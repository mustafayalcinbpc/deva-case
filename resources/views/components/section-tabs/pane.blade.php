@props(['section', 'active' => false])

{{-- Bölüm sekmesinin paneli: tek bir bölümü (#{bölüm}) sarar; sekmesi <x-section-tabs.nav> içinde. --}}
<div id="pane-{{ $section }}" {{ $attributes->class(['tab-pane', 'fade', 'section-tabs__pane', 'section-tabs__pane--'.$section, 'show active' => $active]) }}
     role="tabpanel" aria-labelledby="tab-{{ $section }}" tabindex="0">
    {{ $slot }}
</div>

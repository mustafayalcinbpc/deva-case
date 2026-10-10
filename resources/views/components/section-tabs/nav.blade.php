@props(['tabs', 'label', 'counts' => []])

{{--
    Bölüm sekmelerinin çubuğu (Bootstrap 5 tab). $tabs: [bölüm => sekme adı]; ilk sekme etkin gelir.
    Düğme #tab-{bölüm}, paneli #pane-{bölüm} (<x-section-tabs.pane>); data-section adres çapası olur
    (resources/js/modules/section-tabs.js). $counts: [bölüm => sayı], sekme adının yanında gösterilir.
--}}
<ul {{ $attributes->class(['nav', 'nav-tabs', 'section-tabs__nav']) }} role="tablist" aria-label="{{ $label }}">
    @foreach ($tabs as $section => $text)
        <li class="nav-item" role="presentation">
            <button type="button" id="tab-{{ $section }}" @class(['nav-link', 'section-tabs__tab', 'section-tabs__tab--'.$section, 'active' => $loop->first])
                    data-bs-toggle="tab" data-bs-target="#pane-{{ $section }}" data-section="{{ $section }}"
                    role="tab" aria-controls="pane-{{ $section }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}"
                    @unless ($loop->first) tabindex="-1" @endunless>{{ $text }}@isset($counts[$section]) <span class="section-tabs__count">{{ $counts[$section] }}</span>@endisset</button>
        </li>
    @endforeach
</ul>

@props(['value', 'format' => 'd.m.Y H:i'])

{{--
    Zaman UTC saklanır, config('app.display_timezone') ile gösterilir.
    format: PHP tarih biçimi ya da listeler için kısa biçimler:
      "list"      → "10 Ekim 16:34"  (saatli zamanlar)
      "list-date" → "10 Ekim"        (SKT gibi yalnızca tarih)
    Kısa biçimlerde yıl yalnızca bu yıldan değilse yazılır ("3 Mart 2025 09:12"); tam zaman
    (saniyesiyle) üzerine gelince görünür.
--}}
@php
    $local = $value?->copy()->setTimezone(config('app.display_timezone'));
    $short = in_array($format, ['list', 'list-date'], true);

    if ($local !== null && $short) {
        $sameYear = $local->year === now(config('app.display_timezone'))->year;
        $pattern = 'j F'.($sameYear ? '' : ' Y').($format === 'list' ? ' H:i' : '');
        $text = $local->locale(config('app.locale'))->translatedFormat($pattern);
        $title = $local->format($format === 'list' ? 'd.m.Y H:i:s' : 'd.m.Y');
    } else {
        $text = $local?->format($format);
        $title = null;
    }
@endphp
@if ($local)
    <time {{ $attributes }} datetime="{{ $value->toIso8601String() }}" @if ($title) title="{{ $title }}" @endif>{{ $text }}</time>
@else
    <span {{ $attributes->class('text-body-secondary') }}>—</span>
@endif

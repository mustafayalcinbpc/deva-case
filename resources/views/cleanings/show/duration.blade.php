{{--
    Süre gösterimi. Açık çalışma dilimi varsa ($live) değer tarayıcıda canlı ilerler
    (resources/js/modules/live-duration.js); JS yoksa sayfanın yüklendiği andaki değer görünür.
    Parametreler: $seconds (int), $live (?array: startedAt, base, rate, serverNow).
--}}
@if (! empty($live))
    <span class="live-duration" role="timer" data-module="live-duration" data-started-at="{{ $live['startedAt'] }}" data-base-seconds="{{ $live['base'] }}" data-rate="{{ $live['rate'] }}" data-server-now="{{ $live['serverNow'] }}"><x-duration :seconds="$seconds" /></span>
@else
    <x-duration :seconds="$seconds" />
@endif

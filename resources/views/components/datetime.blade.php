@props(['value', 'format' => 'd.m.Y H:i'])

{{-- Zaman UTC saklanır, config('app.display_timezone') ile gösterilir. --}}
@if ($value)
    <time {{ $attributes }} datetime="{{ $value->toIso8601String() }}">{{ $value->copy()->setTimezone(config('app.display_timezone'))->format($format) }}</time>
@else
    <span {{ $attributes->class('text-body-secondary') }}>—</span>
@endif

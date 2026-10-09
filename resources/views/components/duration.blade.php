@props(['seconds'])

@php
    $seconds = (int) $seconds;
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $rest = $seconds % 60;
    $text = match (true) {
        $hours > 0 => sprintf('%d sa %02d dk', $hours, $minutes),
        $minutes > 0 => sprintf('%d dk %02d sn', $minutes, $rest),
        default => "{$rest} sn",
    };
@endphp

<span {{ $attributes->class('app-duration') }} title="{{ $seconds }} sn">{{ $text }}</span>
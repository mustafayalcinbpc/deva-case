{{-- Baş harf avatarı: ad ve soyadın ilk harfleri. Parametreler: $name, $class (isteğe bağlı). --}}
@php
    $parts = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY);
    $initials = collect([reset($parts), count($parts) > 1 ? end($parts) : null])
        ->filter()
        ->map(fn (string $part) => mb_substr($part, 0, 1))
        ->implode('');
@endphp
<span class="avatar {{ $class ?? '' }}" aria-hidden="true">{{ $initials }}</span>

{{--
    Adımın fotoğrafı ya da videosu (R-05). Parametreler: $media (?array: url, type), $alt (string),
    $preload ('metadata' | 'none').
--}}
@if ($media)
    <figure class="step-media step-media--{{ $media['type'] }}">
        @if ($media['type'] === 'video')
            <video class="step-media__video" src="{{ $media['url'] }}" controls playsinline preload="{{ $preload }}">
                <a href="{{ $media['url'] }}">Videoyu aç</a>
            </video>
        @else
            <img class="step-media__image" src="{{ $media['url'] }}" alt="{{ $alt }}" loading="lazy">
        @endif
    </figure>
@endif

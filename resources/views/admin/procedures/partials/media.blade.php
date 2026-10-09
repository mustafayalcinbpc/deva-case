{{--
    Adımın fotoğrafı ya da videosu (R-05). Parametreler: $step (ProcedureStep), $preview (bool:
    true ise görsel/video gösterilir, false ise yeni sekmede açılan bağlantı).
--}}
@if ($step->media_path)
    @if ($preview)
        <figure class="procedure-media procedure-media--{{ $step->mediaType() }} mb-0">
            @if ($step->mediaType() === 'video')
                <video class="procedure-media__video" src="{{ $step->mediaUrl() }}" controls playsinline preload="metadata">
                    <a href="{{ $step->mediaUrl() }}">Videoyu aç</a>
                </video>
            @else
                <img class="procedure-media__image img-fluid" src="{{ $step->mediaUrl() }}" alt="{{ $step->title }}" loading="lazy">
            @endif
        </figure>
    @else
        <a href="{{ $step->mediaUrl() }}" class="procedure-media-link text-nowrap" target="_blank" rel="noopener">
            @if ($step->mediaType() === 'video')
                <i class="bi bi-camera-video" aria-hidden="true"></i> Video
            @else
                <i class="bi bi-image" aria-hidden="true"></i> Görsel
            @endif
        </a>
    @endif
@endif

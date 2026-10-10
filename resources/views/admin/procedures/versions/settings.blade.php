{{--
    Versiyonun genel bilgisi. Malzeme zorunluluğu elle ayarlanmaz; beklenen malzemeler listesinden
    türetilir (K-13, versions/materials). Yayımlanmış versiyonda yayın tarihi ve bu versiyonla
    açılan kayıt sayısı da gösterilir.
--}}
@php
    $requiredCodes = $version->materials->where('is_required', true)->map(fn ($item) => $item->material->code);
@endphp

<section class="card procedure-settings mb-3" aria-labelledby="procedure-settings-title">
    <div class="card-header">
        <h2 class="card-title" id="procedure-settings-title">Versiyon</h2>
    </div>

    <div class="card-body">
        <dl class="procedure-facts mb-0">
            @unless ($editable)
                <dt>Yayın tarihi</dt>
                <dd><x-datetime :value="$version->published_at" /></dd>
            @endunless
            <dt>Malzeme girişi</dt>
            <dd class="procedure-settings__materials">
                @if ($requiredCodes->isNotEmpty())
                    Zorunlu: {{ $requiredCodes->implode(', ') }}
                @elseif ($version->material_required)
                    Zorunlu (en az bir malzeme)
                @else
                    Zorunlu değil
                @endif
            </dd>
            @unless ($editable)
                <dt>Bu versiyonla açılan kayıt</dt>
                <dd>{{ $version->cleanings_count }}</dd>
            @endunless
        </dl>
    </div>
</section>

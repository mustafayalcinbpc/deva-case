{{--
    Makinenin prosedürü ve yeni kayıtlara uygulanacak versiyonu (K-15, K-18).
    Parametreler: $procedure (?Procedure), $version (?int, yayımlanmış en son versiyon numarası).
--}}
@if ($procedure === null)
    <span class="text-body-secondary">Prosedür atanmamış</span>
@else
    <span class="record-no">{{ $procedure->code }}</span> — {{ $procedure->name }}
    @if ($version !== null)
        <span class="procedure-version">v{{ $version }}</span>
    @else
        <span class="text-body-secondary">(yayımlanmış versiyonu yok)</span>
    @endif
@endif

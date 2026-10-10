{{--
    Üretim iş emrinin bağlantısı: "IST / H01 / M01 — Makine adı", "IST / H01 — Hat adı" ya da bağlantı yok.
    machine.line.facility ve line.facility ilişkileri yüklenmiş olmalıdır.
--}}
@php
    $boundLine = $workOrder->boundLine();
@endphp

@if ($workOrder->machine)
    <span class="work-order-binding" title="{{ $boundLine?->facility?->name }} / {{ $boundLine?->name }} / {{ $workOrder->machine->name }}">
        <span class="work-order-binding__kind">Makine</span>
        {{ $workOrder->locationCodes() }}
    </span>
@elseif ($boundLine)
    <span class="work-order-binding" title="{{ $boundLine->facility?->name }} / {{ $boundLine->name }}">
        <span class="work-order-binding__kind">Hat</span>
        {{ $workOrder->locationCodes() }}
    </span>
@else
    <span class="work-order-binding work-order-binding--none">Bütün makineler</span>
@endif

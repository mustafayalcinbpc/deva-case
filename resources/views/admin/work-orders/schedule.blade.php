{{-- Üretim iş emrinin planlanan zamanı ve tamamlanma anı (gösterim saat diliminde). --}}
@if ($workOrder->planned_start_at || $workOrder->planned_end_at)
    <span class="work-order-schedule">
        <x-datetime :value="$workOrder->planned_start_at" format="list" /> – <x-datetime :value="$workOrder->planned_end_at" format="list" />
    </span>
@else
    <span class="work-order-schedule work-order-schedule--none">Planlanmadı</span>
@endif
@if ($workOrder->completed_at)
    <span class="work-order-schedule__completed d-block">Tamamlandı: <x-datetime :value="$workOrder->completed_at" format="list" /></span>
@endif

{{-- Üretim iş emrinin planlanan zamanı (gösterim saat diliminde). Tamamlanma anı durumla birlikte gösterilir. --}}
@if ($workOrder->planned_start_at || $workOrder->planned_end_at)
    <span class="work-order-schedule">
        <x-datetime :value="$workOrder->planned_start_at" format="list" /> – <x-datetime :value="$workOrder->planned_end_at" format="list" />
    </span>
@else
    <span class="work-order-schedule work-order-schedule--none text-body-secondary">Planlanmadı</span>
@endif

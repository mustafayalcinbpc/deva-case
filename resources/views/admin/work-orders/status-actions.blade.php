{{--
    Üretim iş emrinin durum düğmeleri (K-19, K-20): gerçekte ERP bildirir, demoda yönetici yapar.
    "Tamamlandı" makinedeki "üretim iş emri tamamlanınca" kurallı planlara görev açtırır.
    $size: isteğe bağlı buton boyutu sınıfı (ör. btn-sm).
--}}
@use('App\Enums\WorkOrderStatus')

@if ($workOrder->status->canTransitionTo(WorkOrderStatus::InProduction))
    <form method="POST" action="{{ route('admin.work-orders.start', $workOrder) }}" class="d-inline work-order-status-action work-order-status-action--start" data-module="submit-once">
        @csrf
        <button type="submit" @class(['btn', 'btn-outline-secondary', $size ?? null])>
            <i class="bi bi-play-circle" aria-hidden="true"></i> Üretime al
            <span class="visually-hidden">{{ $workOrder->code }}</span>
        </button>
    </form>
@endif
@if ($workOrder->status->canTransitionTo(WorkOrderStatus::Completed))
    <form method="POST" action="{{ route('admin.work-orders.complete', $workOrder) }}" class="d-inline work-order-status-action work-order-status-action--complete"
          data-module="confirm-submit submit-once"
          data-confirm="{{ $workOrder->code }} tamamlandı olarak işaretlenecek; bu geri alınamaz. Makinede bu tetiğe bağlı temizlik planı varsa yapılması gereken temizlik görevi açılır. Devam edilsin mi?">
        @csrf
        <button type="submit" @class(['btn', 'btn-outline-primary', $size ?? null])>
            <i class="bi bi-check2-square" aria-hidden="true"></i> Tamamlandı
            <span class="visually-hidden">{{ $workOrder->code }}</span>
        </button>
    </form>
@endif

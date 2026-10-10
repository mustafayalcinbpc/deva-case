{{--
    Kullanımdan kaldırma / yeniden kullanıma alma. Plan silinmez (görevler ona bağlıdır).
    $size: isteğe bağlı buton boyutu sınıfı (ör. btn-sm).
--}}
@if ($plan->is_active)
    <form method="POST" action="{{ route('admin.cleaning-plans.deactivate', $plan) }}" class="d-inline cleaning-plan-toggle"
          data-module="confirm-submit submit-once"
          data-confirm="{{ $plan->machine->code }} planı kullanımdan kaldırılacak ve yeni görev üretmeyecek. Açık görevi varsa olduğu gibi kalır. Devam edilsin mi?">
        @csrf
        <button type="submit" @class(['btn', 'btn-outline-danger', $size ?? null])>
            <i class="bi bi-slash-circle" aria-hidden="true"></i> Kullanımdan kaldır
        </button>
    </form>
@else
    <form method="POST" action="{{ route('admin.cleaning-plans.activate', $plan) }}" class="d-inline cleaning-plan-toggle" data-module="submit-once">
        @csrf
        <button type="submit" @class(['btn', 'btn-outline-secondary', $size ?? null])>
            <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Yeniden kullanıma al
        </button>
    </form>
@endif

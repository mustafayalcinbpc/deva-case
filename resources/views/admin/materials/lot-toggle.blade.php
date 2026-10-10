{{--
    Lotu kullanımdan kaldırma / yeniden kullanıma alma. Lot silinmez (kayıtlar ona bağlıdır).
    $size: isteğe bağlı buton boyutu sınıfı (ör. btn-sm).
--}}
@if ($lot->is_active)
    <form method="POST" action="{{ route('admin.materials.lots.deactivate', [$material, $lot]) }}" class="d-inline material-lot-toggle"
          data-module="confirm-submit submit-once"
          data-confirm="{{ $lot->lot_no }} lotu kullanımdan kaldırılacak. Yeni girişlerde seçilemeyecek; girildiği kayıtlarda görünmeye devam edecek. Devam edilsin mi?">
        @csrf
        <button type="submit" @class(['btn', 'btn-outline-danger', $size ?? null])>
            <i class="bi bi-slash-circle" aria-hidden="true"></i> Kullanımdan kaldır
            <span class="visually-hidden">{{ $lot->lot_no }}</span>
        </button>
    </form>
@else
    <form method="POST" action="{{ route('admin.materials.lots.activate', [$material, $lot]) }}" class="d-inline material-lot-toggle" data-module="submit-once">
        @csrf
        <button type="submit" @class(['btn', 'btn-outline-secondary', $size ?? null])>
            <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Yeniden kullanıma al
            <span class="visually-hidden">{{ $lot->lot_no }}</span>
        </button>
    </form>
@endif

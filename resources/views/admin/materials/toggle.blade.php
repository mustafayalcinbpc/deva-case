{{--
    Kullanımdan kaldırma / yeniden kullanıma alma. Malzeme silinmez (kayıtlar ona bağlıdır).
    $size: isteğe bağlı buton boyutu sınıfı (ör. btn-sm).
--}}
@if ($material->is_active)
    <form method="POST" action="{{ route('admin.materials.deactivate', $material) }}" class="d-inline material-toggle"
          data-module="confirm-submit submit-once"
          data-confirm="{{ $material->code }} kullanımdan kaldırılacak. Yeni kayıtlarda ve malzeme eklerken seçilemeyecek; geçmiş kayıtlarda görünmeye devam edecek. Devam edilsin mi?">
        @csrf
        <button type="submit" @class(['btn', 'btn-outline-danger', $size ?? null])>
            <i class="bi bi-slash-circle" aria-hidden="true"></i> Kullanımdan kaldır
        </button>
    </form>
@else
    <form method="POST" action="{{ route('admin.materials.activate', $material) }}" class="d-inline material-toggle" data-module="submit-once">
        @csrf
        <button type="submit" @class(['btn', 'btn-outline-secondary', $size ?? null])>
            <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Yeniden kullanıma al
        </button>
    </form>
@endif

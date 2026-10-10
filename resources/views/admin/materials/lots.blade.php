{{--
    Malzemenin lotları (K-14). SKT lotun özelliğidir ve burada bir kez girilir (gerçekte depo/ERP);
    operatör kayıtta yalnızca kullanımdaki ve SKT'si geçmemiş lotu seçer. Lot silinmez,
    kullanımdan kaldırılır. Parametreler: $material, $lots (cleanings_count ile).
--}}
<section id="material-lots" class="card admin-list material-lots mt-3" aria-labelledby="material-lots-title">
    <div class="card-header">
        <h2 class="card-title" id="material-lots-title">Lotlar</h2>
    </div>

    @if ($lots->isEmpty())
        <div class="card-body">
            <p class="empty-state mb-0">Henüz lot tanımlanmadı. Lotu olmayan malzeme kayıtta seçilemez.</p>
        </div>
    @else
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 admin-list__table material-lots__table">
                    <thead>
                        <tr>
                            <th scope="col">Lot no</th>
                            <th scope="col">Son kullanma</th>
                            <th scope="col">Giriş</th>
                            <th scope="col">Durum</th>
                            <th scope="col" class="text-end" title="Lotun girildiği temizlik kaydı sayısı">Kullanıldığı kayıt</th>
                            <th scope="col"><span class="visually-hidden">İşlemler</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($lots as $lot)
                            <tr id="material-lot-{{ $lot->id }}" @class([
                                'admin-list__row',
                                'admin-list__row--inactive' => ! $lot->is_active || $lot->isExpiredOn(now()),
                            ])>
                                <td class="text-nowrap"><span class="record-no">{{ $lot->lot_no }}</span></td>
                                <td class="text-nowrap"><time datetime="{{ $lot->expiry_date->toDateString() }}">{{ $lot->expiry_date->format('d.m.Y') }}</time></td>
                                <td class="text-nowrap">
                                    @if ($lot->received_at)
                                        <time datetime="{{ $lot->received_at->toDateString() }}">{{ $lot->received_at->format('d.m.Y') }}</time>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>@include('admin.materials.lot-state', ['lot' => $lot])</td>
                                <td class="text-end">{{ $lot->cleanings_count }}</td>
                                <td class="text-end text-nowrap admin-list__actions">
                                    <a href="{{ route('admin.materials.lots.edit', [$material, $lot]) }}" class="btn btn-sm btn-outline-secondary">
                                        <i class="bi bi-pencil" aria-hidden="true"></i> Düzenle
                                        <span class="visually-hidden">{{ $lot->lot_no }}</span>
                                    </a>
                                    @include('admin.materials.lot-toggle', ['material' => $material, 'lot' => $lot, 'size' => 'btn-sm'])
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.materials.lots.store', $material) }}" class="card-footer material-lots__add" data-module="submit-once" aria-labelledby="material-lot-add-title">
        @csrf
        <h3 class="h6" id="material-lot-add-title">Lot ekle</h3>
        @include('admin.materials.lot-fields', ['lot' => null])
        <button type="submit" class="btn btn-outline-primary mt-3">
            <i class="bi bi-plus-circle" aria-hidden="true"></i> Lotu ekle
        </button>
    </form>
</section>

{{--
    Versiyonun beklediği malzemeler (K-12, K-13). Kayıt formunda bu malzemeler kendiliğinden
    gelir ve operatör her biri için lot seçer; zorunlu malzemelerin her biri için geçerli lot
    girilmeden ilk adım başlatılamaz. Taslakta malzeme eklenir, zorunlu/isteğe bağlı yapılır,
    sıralanır ve çıkarılır; yayımlanmış versiyonun listesi değişmez (K-15). JS gerektirmez.
--}}
<section id="procedure-materials" class="card procedure-materials mb-3" aria-labelledby="procedure-materials-title">
    <div class="card-header">
        <h2 class="card-title" id="procedure-materials-title">Beklenen malzemeler</h2>
    </div>

    <div class="card-body">
        @include('admin.procedures.partials.errors', ['keys' => ['material_id', 'is_required']])

        @if ($version->materials->isEmpty())
            <p class="empty-state procedure-materials__empty">
                Liste boş: kayıtta malzeme kendiliğinden gelmez.
                @if ($version->material_required)
                    Bu versiyon liste tanımlanmadan önceki kuralla en az bir geçerli malzeme ister.
                @endif
            </p>
        @else
            <ol class="list-unstyled procedure-materials__list">
                @foreach ($version->materials as $item)
                    <li id="procedure-material-{{ $item->id }}" @class([
                        'procedure-materials__item',
                        'procedure-materials__item--required' => $item->is_required,
                        'procedure-materials__item--inactive' => ! $item->material->is_active,
                    ])>
                        <div class="procedure-materials__label">
                            <span class="record-no">{{ $item->material->code }}</span>
                            <span class="procedure-materials__name">{{ $item->material->name }}</span>
                            <span class="procedure-materials__requirement">{{ $item->is_required ? 'Zorunlu' : 'İsteğe bağlı' }}</span>
                            @unless ($item->material->is_active)
                                <span class="status-badge status-badge--retired">Kullanımdan kaldırıldı</span>
                            @endunless
                        </div>

                        @if ($editable)
                            <div class="procedure-materials__actions">
                                @include('admin.procedures.partials.move', [
                                    'up' => route('admin.procedures.materials.move', [$procedure, $version, $item, 'up']),
                                    'down' => route('admin.procedures.materials.move', [$procedure, $version, $item, 'down']),
                                    'first' => $loop->first,
                                    'last' => $loop->last,
                                    'label' => $item->material->code,
                                ])
                                <form method="POST" action="{{ route('admin.procedures.materials.update', [$procedure, $version, $item]) }}" class="d-inline procedure-materials__toggle" data-module="submit-once">
                                    @csrf
                                    @method('PUT')
                                    @unless ($item->is_required)
                                        <input type="hidden" name="is_required" value="1">
                                    @endunless
                                    <button type="submit" class="btn btn-sm btn-outline-secondary">
                                        {{ $item->is_required ? 'İsteğe bağlı yap' : 'Zorunlu yap' }}
                                        <span class="visually-hidden">{{ $item->material->code }}</span>
                                    </button>
                                </form>
                                <form method="POST"
                                      action="{{ route('admin.procedures.materials.destroy', [$procedure, $version, $item]) }}"
                                      class="d-inline"
                                      data-module="confirm-submit submit-once"
                                      data-confirm="{{ $item->material->code }} listeden çıkarılsın mı?">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-secondary" aria-label="{{ $item->material->code }}: listeden çıkar">
                                        <i class="bi bi-trash" aria-hidden="true"></i> Çıkar
                                    </button>
                                </form>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif

        @if ($editable)
            @if ($materialChoices->isEmpty())
                <p class="form-text mb-0 procedure-materials__no-choice">Eklenebilecek başka kullanımda malzeme yok. Malzemeler Yönetim › Malzemeler'de tanımlanır.</p>
            @else
                <form method="POST" action="{{ route('admin.procedures.materials.store', [$procedure, $version]) }}" class="procedure-materials__add" data-module="submit-once">
                    @csrf
                    <div class="mb-2">
                        <label for="procedure-material-id" class="form-label">Malzeme ekle</label>
                        <select id="procedure-material-id" name="material_id" required @class(['form-select', 'is-invalid' => $errors->has('material_id')])>
                            <option value="">Malzeme seçin</option>
                            @foreach ($materialChoices as $material)
                                <option value="{{ $material->id }}" @selected((string) old('material_id') === (string) $material->id)>{{ $material->code }} — {{ $material->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-check mb-2">
                        <input type="checkbox" id="procedure-material-required" name="is_required" value="1" class="form-check-input"
                               @checked(old('_token') === null || old('is_required')) aria-describedby="procedure-material-required-help">
                        <label for="procedure-material-required" class="form-check-label">Zorunlu</label>
                        <div id="procedure-material-required-help" class="form-text">
                            Zorunlu malzeme için geçerli lot girilmeden kaydın ilk adımı başlatılamaz (K-12).
                        </div>
                    </div>
                    <button type="submit" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-plus-circle" aria-hidden="true"></i> Listeye ekle
                    </button>
                </form>
            @endif
        @endif
    </div>
</section>

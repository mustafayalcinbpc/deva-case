{{--
    Kayıtta kullanılan malzemeler: kod, lot, son kullanma tarihi (R-07, R-10). Yanlış girilen
    malzeme silinmez; gerekçeyle geçersiz kılınır ve ilk kaydı olduğu gibi görünür (K-12).
    Ekleme ve geçersiz kılma formları yalnızca kayıt açıkken ve kaydın sahibi ya da bir adımın
    görevlisi için gösterilir.

    Geçersiz kılma formu her satırda ayrıdır; doğrulama hatasından sonra hatanın hangi satıra
    ait olduğu, formla gönderilen void_material_id alanından anlaşılır (aksiyon bu alanı kullanmaz).
--}}
@use('App\Enums\CleaningStatus')

@php
    $voidTarget = (int) old('void_material_id');
    $materialFormOpen = $errors->hasAny(['material_id', 'lot_no', 'expiry_date'])
        || old('material_id') !== null
        || ($materialMissing && $cleaning->status === CleaningStatus::Created);
@endphp

<section id="materials" class="card materials-card" aria-labelledby="materials-title">
    <div class="card-header">
        <h2 class="card-title" id="materials-title">Malzemeler</h2>
    </div>

    <div class="card-body">
        <p class="materials-card__requirement">
            {{ $cleaning->procedureVersion->material_required ? 'Bu prosedürde malzeme kullanımı zorunludur.' : 'Bu prosedürde malzeme kullanımı zorunlu değildir.' }}
        </p>

        @if ($materialMissing && $cleaning->status === CleaningStatus::Created)
            <div class="alert alert-warning materials-card__missing" role="alert">
                Henüz geçerli malzeme yok. En az bir geçerli malzeme girilmeden ilk adım başlatılamaz.
            </div>
        @endif

        @if ($errors->has('void_reason') && $cleaning->materials->doesntContain('id', $voidTarget))
            <div class="alert alert-danger materials-card__error" role="alert">{{ $errors->first('void_reason') }}</div>
        @endif

        @if ($cleaning->materials->isEmpty())
            <p class="empty-state">Malzeme girilmedi.</p>
        @else
            <ul class="materials-list">
                @foreach ($cleaning->materials as $item)
                    @php
                        $voided = $item->voided_at !== null;
                        $isVoidTarget = $voidTarget === $item->id;
                    @endphp

                    <li id="material-{{ $item->id }}" @class([
                        'materials-list__item',
                        'materials-list__item--voided' => $voided,
                        'materials-list__item--valid' => ! $voided,
                    ])>
                        <div class="materials-list__head">
                            <span class="materials-list__code">{{ $item->material->code }}</span>
                            <span class="materials-list__name">{{ $item->material->name }}</span>
                            @if ($voided)
                                <span class="materials-list__state">Geçersiz</span>
                            @endif
                        </div>

                        <dl class="materials-list__facts">
                            <div class="materials-list__fact">
                                <dt>Lot</dt>
                                <dd class="materials-list__lot">{{ $item->lot_no }}</dd>
                            </div>
                            <div class="materials-list__fact">
                                <dt>Son kullanma</dt>
                                <dd><time datetime="{{ $item->expiry_date->toDateString() }}">{{ $item->expiry_date->format('d.m.Y') }}</time></dd>
                            </div>
                            <div class="materials-list__fact">
                                <dt>Ekleyen</dt>
                                <dd>
                                    {{ $users->get($item->added_by)?->name ?? '—' }},
                                    <x-datetime :value="$item->created_at" format="d.m.Y H:i:s" />
                                </dd>
                            </div>
                            @if ($voided)
                                <div class="materials-list__fact">
                                    <dt>Geçersiz kılan</dt>
                                    <dd>
                                        {{ $users->get($item->voided_by)?->name ?? '—' }},
                                        <x-datetime :value="$item->voided_at" format="d.m.Y H:i:s" />
                                    </dd>
                                </div>
                                <div class="materials-list__fact materials-list__fact--wide">
                                    <dt>Gerekçe</dt>
                                    <dd class="materials-list__void-reason">{{ $item->void_reason }}</dd>
                                </div>
                            @endif
                        </dl>

                        @if ($canManageMaterials && ! $voided)
                            <details class="materials-list__void" @if ($isVoidTarget) open @endif>
                                <summary>Geçersiz kıl</summary>
                                <form method="POST" action="{{ route('cleanings.materials.void', [$cleaning, $item]) }}" class="materials-list__void-form" data-module="submit-once">
                                    @csrf
                                    <input type="hidden" name="void_material_id" value="{{ $item->id }}">
                                    <label for="void-reason-{{ $item->id }}" class="form-label">Gerekçe</label>
                                    <textarea id="void-reason-{{ $item->id }}" name="void_reason" rows="2" required
                                              @class(['form-control', 'is-invalid' => $isVoidTarget && $errors->has('void_reason')])>{{ $isVoidTarget ? old('void_reason') : '' }}</textarea>
                                    @if ($isVoidTarget)
                                        @error('void_reason')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    @endif
                                    <p class="form-text">Malzeme silinmez; geçersiz olarak işaretlenir ve ilk kaydı görünmeye devam eder.</p>
                                    <button type="submit" class="btn btn-outline-danger materials-list__void-submit">Geçersiz kıl</button>
                                </form>
                            </details>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($canManageMaterials)
            <details class="material-form" @if ($materialFormOpen) open @endif>
                <summary class="material-form__toggle">
                    <i class="bi bi-plus-circle" aria-hidden="true"></i> Malzeme ekle
                </summary>

                <form method="POST" action="{{ route('cleanings.materials.store', $cleaning) }}" class="material-form__form" data-module="submit-once">
                    @csrf

                    <div class="material-form__field">
                        <label for="material-id" class="form-label">Malzeme</label>
                        <select id="material-id" name="material_id" required @class(['form-select', 'is-invalid' => $errors->has('material_id')])>
                            <option value="">Seçin</option>
                            @foreach ($catalog as $material)
                                <option value="{{ $material->id }}" @selected((string) old('material_id') === (string) $material->id)>{{ $material->code }} — {{ $material->name }}</option>
                            @endforeach
                        </select>
                        @error('material_id')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="material-form__field">
                        <label for="lot-no" class="form-label">Lot numarası</label>
                        <input type="text" id="lot-no" name="lot_no" value="{{ old('lot_no') }}" required autocomplete="off"
                               @class(['form-control', 'is-invalid' => $errors->has('lot_no')])>
                        @error('lot_no')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="material-form__field">
                        <label for="expiry-date" class="form-label">Son kullanma tarihi</label>
                        <input type="date" id="expiry-date" name="expiry_date" value="{{ old('expiry_date') }}" required
                               @class(['form-control', 'is-invalid' => $errors->has('expiry_date')])>
                        @error('expiry_date')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <button type="submit" class="btn btn-primary material-form__submit">Malzemeyi ekle</button>
                </form>
            </details>
        @endif
    </div>
</section>

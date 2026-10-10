{{--
    K-12, K-13, K-14: malzemeler. Seçilen makinenin prosedür versiyonunun beklediği malzemeler satır
    olarak gelir (material-expected; her makinenin satırları ayrı fieldset'te, yalnızca seçili
    makineninki etkin ve gönderilir). Operatör yalnızca lotu seçer; lot no ve SKT lot kaydından
    gelir. Lotu seçilmemiş satır yok sayılır (StoreCleaningRequest); zorunluluk ilk adım başlatılırken
    kontrol edilir, kayıt lotsuz da açılabilir. Ek satırlar prosedürde olmayan malzeme ya da ikinci
    lot içindir; JS olmadan tek ek satır gönderilir, JS satır ekler ve çıkarır.
--}}
@php
    $oldRows = old('materials');
    $oldRows = is_array($oldRows) ? $oldRows : [];
    // Ek satırların anahtarı sayıdır; prosedürden gelen satırlarınki "p{malzeme id}".
    $extraRows = array_filter($oldRows, fn (mixed $row, int|string $key) => is_int($key), ARRAY_FILTER_USE_BOTH);
    $extraRows = $extraRows !== [] ? $extraRows : [[]];
    $nextMaterialIndex = max(array_keys($extraRows)) + 1;
    // Görevden gelindiyse makine görevden gelir (K-21).
    $selectedMachine = (string) (($task ?? null)?->machine_id ?? old('machine_id'));
@endphp

<section class="card cleaning-form__section" aria-labelledby="cleaning-form-materials">
    <div class="card-header">
        <h2 class="card-title" id="cleaning-form-materials">Malzemeler</h2>
        <span class="card-meta">Temizlik sürerken de eklenebilir</span>
    </div>

    <div class="card-body">
        <p id="materials-help" class="form-text cleaning-form__section-help">
            Prosedürün beklediği malzemeler makineyi seçince listelenir; her biri için kullandığınız lotu seçin. Lot numarası
            ve son kullanma tarihi lot kaydından gelir. Lotu seçilmeyen satırlar dikkate alınmaz; temizlik sürerken de malzeme
            eklenebilir.
        </p>

        <div class="alert alert-info material-required-hint" role="note" data-material-required-hint hidden>
            Bu makinenin prosedüründe zorunlu malzeme var. Kaydı lotsuz açabilirsiniz, ancak zorunlu malzemelerin her biri için
            lot girilmeden ilk adım başlatılamaz.
        </div>

        @error('materials')
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror

        <div class="expected-materials" aria-live="polite">
            <p class="form-text expected-materials__empty" data-expected-materials="" @if ($selectedMachine !== '') hidden @endif>
                Makineyi seçtiğinizde prosedürün beklediği malzemeler burada listelenir.
            </p>
            @foreach ($machineGroups->flatten(1) as $machine)
                @include('cleanings.form.material-expected', [
                    'machine' => $machine,
                    'active' => $selectedMachine === (string) $machine->id,
                    'oldRows' => $oldRows,
                ])
            @endforeach
        </div>

        <h3 class="material-rows__title" id="extra-materials-title">Ek malzeme</h3>
        <div class="material-rows" data-material-rows data-next-index="{{ $nextMaterialIndex }}" role="group" aria-labelledby="extra-materials-title">
            @foreach ($extraRows as $index => $row)
                @include('cleanings.form.material-row', ['index' => $index, 'row' => is_array($row) ? $row : []])
            @endforeach
        </div>

        <template data-material-template>
            @include('cleanings.form.material-row', ['index' => '__INDEX__', 'row' => []])
        </template>

        <button type="button" class="btn btn-outline-secondary material-rows__add" data-material-add hidden>
            <i class="bi bi-plus-lg" aria-hidden="true"></i> Ek malzeme ekle
        </button>
    </div>
</section>

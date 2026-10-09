{{--
    K-12, K-13, K-14: malzeme katalogdan seçilir, lot ve son kullanma tarihi girilir. Üç alanı da boş
    satırlar yok sayılır (StoreCleaningRequest). Zorunluluk ilk adım başlatılırken kontrol edilir;
    kayıt malzemesiz de açılabilir. JS olmadan tek satır gönderilir; JS satır ekler ve çıkarır.
--}}
@php
    $materialRows = old('materials');
    $materialRows = is_array($materialRows) && $materialRows !== [] ? $materialRows : [[]];
    $nextMaterialIndex = max(array_map(intval(...), array_keys($materialRows))) + 1;
@endphp

<section class="card cleaning-form__section" aria-labelledby="cleaning-form-materials">
    <div class="card-header">
        <h2 class="card-title" id="cleaning-form-materials">Malzemeler</h2>
        <span class="card-meta">Temizlik sürerken de eklenebilir</span>
    </div>

    <div class="card-body">
        <p id="materials-help" class="form-text cleaning-form__section-help">
            Malzemeyi katalogdan seçin, lot numarasını ve son kullanma tarihini girin. Boş bırakılan satırlar dikkate alınmaz;
            temizlik sürerken de malzeme eklenebilir.
        </p>

        <div class="alert alert-info material-required-hint" role="note" data-material-required-hint hidden>
            Bu makinenin prosedüründe malzeme zorunlu. Kaydı malzemesiz açabilirsiniz, ancak en az bir geçerli malzeme
            girilmeden ilk adım başlatılamaz.
        </div>

        @error('materials')
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror

        <div class="material-rows" data-material-rows data-next-index="{{ $nextMaterialIndex }}">
            @foreach ($materialRows as $index => $row)
                @include('cleanings.form.material-row', ['index' => $index, 'row' => is_array($row) ? $row : []])
            @endforeach
        </div>

        <template data-material-template>
            @include('cleanings.form.material-row', ['index' => '__INDEX__', 'row' => []])
        </template>

        <button type="button" class="btn btn-outline-secondary material-rows__add" data-material-add hidden>
            <i class="bi bi-plus-lg" aria-hidden="true"></i> Malzeme ekle
        </button>
    </div>
</section>

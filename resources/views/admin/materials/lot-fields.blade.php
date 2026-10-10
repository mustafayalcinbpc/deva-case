{{--
    Lot alanları (K-14): lot no, son kullanma tarihi, giriş tarihi. $lot: düzenlenen lot ya da
    null (yeni lot); $lotLocked: kayıtlarda kullanılan lotun numarası değişmez.
--}}
@php($lotLocked ??= false)

<div class="row g-3 material-lot-fields">
    <div class="col-sm-4">
        <label for="lot_no" class="form-label">Lot numarası</label>
        <input type="text"
               id="lot_no"
               name="lot_no"
               value="{{ old('lot_no', $lot?->lot_no) }}"
               maxlength="100"
               required
               autocomplete="off"
               @readonly($lotLocked)
               @class(['form-control', 'is-invalid' => $errors->has('lot_no')])
               aria-describedby="lot_no-help @error('lot_no') lot_no-error @enderror">
        @error('lot_no')
            <div id="lot_no-error" class="invalid-feedback">{{ $message }}</div>
        @enderror
        <div id="lot_no-help" class="form-text">
            @if ($lotLocked)
                Kayıtlarda kullanıldığı için değiştirilemez.
            @else
                Malzeme içinde tekildir, ör. DT-24118.
            @endif
        </div>
    </div>

    <div class="col-sm-4">
        <label for="expiry_date" class="form-label">Son kullanma tarihi</label>
        <input type="date"
               id="expiry_date"
               name="expiry_date"
               value="{{ old('expiry_date', $lot?->expiry_date?->toDateString()) }}"
               required
               @class(['form-control', 'is-invalid' => $errors->has('expiry_date')])
               aria-describedby="expiry_date-help @error('expiry_date') expiry_date-error @enderror">
        @error('expiry_date')
            <div id="expiry_date-error" class="invalid-feedback">{{ $message }}</div>
        @enderror
        <div id="expiry_date-help" class="form-text">Bu tarihten sonra lot yeni girişte seçilemez.</div>
    </div>

    <div class="col-sm-4">
        <label for="received_at" class="form-label">Giriş tarihi <span class="form-text">(isteğe bağlı)</span></label>
        <input type="date"
               id="received_at"
               name="received_at"
               value="{{ old('received_at', $lot?->received_at?->toDateString()) }}"
               @class(['form-control', 'is-invalid' => $errors->has('received_at')])
               @error('received_at') aria-describedby="received_at-error" @enderror>
        @error('received_at')
            <div id="received_at-error" class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>

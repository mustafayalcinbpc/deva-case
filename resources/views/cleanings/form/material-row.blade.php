{{-- Tek malzeme satırı. $index: satır anahtarı (şablonda __INDEX__), $row: önceki girdi. --}}
@php
    $key = "materials.{$index}";
    $id = "materials-{$index}";
    $value = fn (string $field): string => is_scalar($row[$field] ?? null) ? (string) $row[$field] : '';
@endphp

<div class="material-row" data-material-row>
    <div class="material-row__field material-row__field--material">
        <label for="{{ $id }}-material_id" class="form-label">Malzeme</label>
        <select id="{{ $id }}-material_id"
                name="materials[{{ $index }}][material_id]"
                @class(['form-select', 'is-invalid' => $errors->has("{$key}.material_id")])
                @error("{$key}.material_id") aria-describedby="{{ $id }}-material_id-error" @enderror>
            <option value="">Malzeme seçin</option>
            @foreach ($materials as $material)
                <option value="{{ $material->id }}" @selected($value('material_id') === (string) $material->id)>{{ $material->code }} — {{ $material->name }}</option>
            @endforeach
        </select>
        @error("{$key}.material_id")
            <div id="{{ $id }}-material_id-error" class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="material-row__field material-row__field--lot">
        <label for="{{ $id }}-lot_no" class="form-label">Lot numarası</label>
        <input type="text"
               id="{{ $id }}-lot_no"
               name="materials[{{ $index }}][lot_no]"
               value="{{ $value('lot_no') }}"
               @class(['form-control', 'is-invalid' => $errors->has("{$key}.lot_no")])
               @error("{$key}.lot_no") aria-describedby="{{ $id }}-lot_no-error" @enderror
               maxlength="255"
               autocomplete="off">
        @error("{$key}.lot_no")
            <div id="{{ $id }}-lot_no-error" class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="material-row__field material-row__field--expiry">
        <label for="{{ $id }}-expiry_date" class="form-label">Son kullanma tarihi</label>
        <input type="date"
               id="{{ $id }}-expiry_date"
               name="materials[{{ $index }}][expiry_date]"
               value="{{ $value('expiry_date') }}"
               @class(['form-control', 'is-invalid' => $errors->has("{$key}.expiry_date")])
               @error("{$key}.expiry_date") aria-describedby="{{ $id }}-expiry_date-error" @enderror>
        @error("{$key}.expiry_date")
            <div id="{{ $id }}-expiry_date-error" class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="material-row__actions">
        <button type="button" class="btn btn-outline-secondary material-row__remove" data-material-remove hidden>
            <i class="bi bi-trash" aria-hidden="true"></i> Kaldır
        </button>
    </div>
</div>

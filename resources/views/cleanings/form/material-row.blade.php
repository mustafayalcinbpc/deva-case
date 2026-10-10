{{--
    Ek malzeme satırı (K-12, K-14): malzeme ve lot tek seçimde, malzemeye göre gruplu; yalnızca
    kullanımdaki ve son kullanma tarihi geçmemiş lotlar. $index: satır anahtarı (şablonda __INDEX__),
    $row: önceki girdi.
--}}
@php
    $key = "materials.{$index}";
    $id = "materials-{$index}";
    $selectedLot = is_scalar($row['material_lot_id'] ?? null) ? (string) $row['material_lot_id'] : '';
@endphp

<div class="material-row material-row--extra" data-material-row>
    <div class="material-row__field material-row__field--lot">
        <label for="{{ $id }}-material_lot_id" class="form-label">Malzeme ve lot</label>
        <select id="{{ $id }}-material_lot_id"
                name="materials[{{ $index }}][material_lot_id]"
                @class(['form-select', 'is-invalid' => $errors->has("{$key}.material_lot_id")])
                @error("{$key}.material_lot_id") aria-describedby="{{ $id }}-material_lot_id-error" @enderror>
            <option value="">Malzeme ve lot seçin</option>
            @foreach ($materials as $material)
                @continue(! $lotsByMaterial->has($material->id))
                <optgroup label="{{ $material->code }} — {{ $material->name }}">
                    @foreach ($lotsByMaterial->get($material->id) as $lot)
                        <option value="{{ $lot->id }}" @selected($selectedLot === (string) $lot->id)>{{ $lot->lot_no }} · SKT {{ $lot->expiry_date->format('d.m.Y') }}</option>
                    @endforeach
                </optgroup>
            @endforeach
        </select>
        @error("{$key}.material_lot_id")
            <div id="{{ $id }}-material_lot_id-error" class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="material-row__actions">
        <button type="button" class="btn btn-outline-secondary material-row__remove" data-material-remove hidden>
            <i class="bi bi-trash" aria-hidden="true"></i> Kaldır
        </button>
    </div>
</div>

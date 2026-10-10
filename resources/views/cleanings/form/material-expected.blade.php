{{--
    Makinenin prosedür versiyonunun beklediği malzemeler (K-13): her biri için lot seçimi (K-14).
    Yalnızca seçili makinenin fieldset'i görünür ve etkindir; diğerleri devre dışıdır, gönderilmez
    (cleaning-form.js makine değişince değiştirir). $machine, $active, $oldRows: önceki girdi.
--}}
@php
    $expected = $machine->currentVersion->materials;
@endphp

<fieldset class="expected-materials__group" data-expected-materials="{{ $machine->id }}" @unless ($active) hidden disabled @endunless>
    <legend class="visually-hidden">{{ $machine->code }} — {{ $machine->name }}: prosedürün beklediği malzemeler</legend>

    @if ($expected->isEmpty())
        <p class="form-text expected-materials__none">
            Bu makinenin prosedüründe beklenen malzeme yok. Kullandığınız malzeme varsa ek malzeme olarak ekleyin.
        </p>
    @else
        <ul class="expected-materials__rows">
            @foreach ($expected as $item)
                @php
                    $key = "p{$item->material_id}";
                    $id = "materials-m{$machine->id}-{$key}";
                    $lots = $lotsByMaterial->get($item->material_id, collect());
                    $selectedLot = $active ? (string) data_get($oldRows, "{$key}.material_lot_id", '') : '';
                    $error = $active ? $errors->first("materials.{$key}.material_lot_id") : '';
                @endphp

                <li @class(['material-row', 'material-row--expected', 'material-row--required' => $item->is_required]) data-expected-material="{{ $item->material_id }}">
                    <input type="hidden" name="materials[{{ $key }}][material_id]" value="{{ $item->material_id }}">

                    <div class="material-row__field material-row__field--material">
                        <span class="material-row__material">
                            <span class="material-row__code">{{ $item->material->code }}</span> — {{ $item->material->name }}
                        </span>
                        <span @class(['material-row__requirement', 'material-row__requirement--required' => $item->is_required])>
                            {{ $item->is_required ? 'Zorunlu' : 'İsteğe bağlı' }}
                        </span>
                    </div>

                    <div class="material-row__field material-row__field--lot">
                        <label for="{{ $id }}-lot" class="form-label">Lot <span class="visually-hidden">({{ $item->material->code }})</span></label>
                        @if ($lots->isEmpty())
                            <select id="{{ $id }}-lot" class="form-select" disabled aria-describedby="{{ $id }}-no-lot">
                                <option>Kullanılabilir lot yok</option>
                            </select>
                            <div id="{{ $id }}-no-lot" class="form-text">Bu malzemenin kullanımda ve son kullanma tarihi geçmemiş lotu yok; lotları yönetici tanımlar.</div>
                        @else
                            <select id="{{ $id }}-lot"
                                    name="materials[{{ $key }}][material_lot_id]"
                                    @class(['form-select', 'is-invalid' => $error !== ''])
                                    @if ($error !== '') aria-describedby="{{ $id }}-lot-error" @endif>
                                <option value="">Lot seçin</option>
                                @foreach ($lots as $lot)
                                    <option value="{{ $lot->id }}" @selected($selectedLot === (string) $lot->id)>{{ $lot->lot_no }} · SKT {{ $lot->expiry_date->format('d.m.Y') }}</option>
                                @endforeach
                            </select>
                        @endif
                        @if ($error !== '')
                            <div id="{{ $id }}-lot-error" class="invalid-feedback">{{ $error }}</div>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</fieldset>

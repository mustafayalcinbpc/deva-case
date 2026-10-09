{{--
    Faz alanları (ekleme ve düzenleme formu). Parametre: $phase (?ProcedurePhase).
    Minimum süre dakika olarak girilir, saniye olarak saklanır (R-06). Doğrulama hatasında
    işaret kutusu gönderilen değerle gelir.
--}}
@php
    $fromOld = session()->hasOldInput('name');
    $minutes = $phase !== null ? intdiv($phase->min_duration_seconds, 60) : 0;
@endphp

<div class="mb-3">
    <label for="phase-name" class="form-label">Faz adı</label>
    <input type="text"
           id="phase-name"
           name="name"
           value="{{ old('name', $phase?->name) }}"
           maxlength="255"
           required
           @class(['form-control', 'is-invalid' => $errors->has('name')])
           @error('name') aria-describedby="phase-name-error" @enderror>
    @error('name')
        <div id="phase-name-error" class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="mb-3">
    <label for="phase-min-duration" class="form-label">Minimum süre</label>
    <div @class(['input-group', 'has-validation' => $errors->has('min_duration_minutes')])>
        <input type="number"
               id="phase-min-duration"
               name="min_duration_minutes"
               value="{{ old('min_duration_minutes', $minutes) }}"
               min="0"
               max="{{ \App\Http\Requests\Admin\Procedures\ProcedurePhaseRequest::MAX_MINUTES }}"
               step="1"
               inputmode="numeric"
               required
               @class(['form-control', 'is-invalid' => $errors->has('min_duration_minutes')])
               aria-describedby="phase-min-duration-help @error('min_duration_minutes') phase-min-duration-error @enderror">
        <span class="input-group-text">dk</span>
        @error('min_duration_minutes')
            <div id="phase-min-duration-error" class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div id="phase-min-duration-help" class="form-text">
        0 ise minimum süre yoktur. Daha kısa süren faz kapanır ama sapma olarak işaretlenir ve gerekçe istenir (R-06, K-01).
    </div>
</div>

<div class="form-check mb-0">
    <input type="checkbox"
           id="phase-include-gaps"
           name="include_gaps"
           value="1"
           @checked($fromOld ? old('include_gaps') : $phase?->include_gaps)
           @class(['form-check-input', 'is-invalid' => $errors->has('include_gaps')])
           aria-describedby="phase-include-gaps-help">
    <label for="phase-include-gaps" class="form-check-label">Adımlar arasındaki boşluklar faz süresine dahil</label>
    @error('include_gaps')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
    <div id="phase-include-gaps-help" class="form-text">
        K-02: işaretliyse faz süresi ilk adımın başlangıcından son adımın bitişine kadar (brüt) ölçülür; değilse yalnızca adımlarda çalışılan süre (net) sayılır. Minimum süre bu süreyle karşılaştırılır.
    </div>
</div>

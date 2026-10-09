{{--
    Tanım kodu alanı (tesis, hat, makine). K-17: kod kayıt numarasının parçasıdır; tanım için
    kayıt açılmışsa alan salt okunur gösterilir ve nedeni yazılır.
    Parametreler: $value, $locked (bool), $reason (kilit nedeni), $help (açıklama), $example.
--}}
<div class="mb-3 definition-form__field">
    <label for="code" class="form-label">Kod</label>
    @if ($locked)
        <input type="text"
               id="code"
               name="code"
               value="{{ $value }}"
               class="form-control"
               readonly
               aria-describedby="code-locked">
        <div id="code-locked" class="form-text definition-form__locked">
            <i class="bi bi-lock" aria-hidden="true"></i> {{ $reason }}
        </div>
    @else
        <input type="text"
               id="code"
               name="code"
               value="{{ old('code', $value) }}"
               maxlength="{{ \App\Http\Requests\Admin\Locations\LocationRequest::CODE_MAX }}"
               autocapitalize="characters"
               autocomplete="off"
               spellcheck="false"
               @class(['form-control', 'definition-form__code', 'is-invalid' => $errors->has('code')])
               aria-describedby="code-help @error('code') code-error @enderror"
               required>
        <div id="code-help" class="form-text">{{ $help }} Örnek: {{ $example }}.</div>
    @endif
    @error('code')
        <div id="code-error" class="invalid-feedback d-block">{{ $message }}</div>
    @enderror
</div>

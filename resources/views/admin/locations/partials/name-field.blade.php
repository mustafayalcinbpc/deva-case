{{-- Tanım adı alanı (tesis, hat, makine). Parametre: $value. --}}
<div class="mb-3 definition-form__field">
    <label for="name" class="form-label">Ad</label>
    <input type="text"
           id="name"
           name="name"
           value="{{ old('name', $value) }}"
           maxlength="255"
           @class(['form-control', 'is-invalid' => $errors->has('name')])
           @error('name') aria-describedby="name-error" @enderror
           required>
    @error('name')
        <div id="name-error" class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

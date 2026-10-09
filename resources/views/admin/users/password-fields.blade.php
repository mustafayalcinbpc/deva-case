{{-- Şifre ve onayı (yeni kullanıcı ve şifre sıfırlama). $label: şifre alanının etiketi. --}}
<div class="mb-3">
    <label for="password" class="form-label">{{ $label }}</label>
    <input type="password"
           id="password"
           name="password"
           minlength="{{ \App\Http\Requests\Admin\Catalog\StoreUserRequest::PASSWORD_MIN }}"
           required
           autocomplete="new-password"
           @class(['form-control', 'is-invalid' => $errors->has('password')])
           aria-describedby="password-help @error('password') password-error @enderror">
    @error('password')
        <div id="password-error" class="invalid-feedback">{{ $message }}</div>
    @enderror
    <div id="password-help" class="form-text">En az {{ \App\Http\Requests\Admin\Catalog\StoreUserRequest::PASSWORD_MIN }} karakter.</div>
</div>

<div class="mb-0">
    <label for="password_confirmation" class="form-label">{{ $label }} (tekrar)</label>
    <input type="password"
           id="password_confirmation"
           name="password_confirmation"
           required
           autocomplete="new-password"
           class="form-control">
</div>

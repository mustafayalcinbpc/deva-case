{{--
    Ad, e-posta ve rol alanları (yeni kullanıcı ve düzenleme). $user: düzenlenen kullanıcı ya da
    boş model; $isSelf: yönetici kendi hesabını düzenliyor mu.
--}}
<div class="mb-3">
    <label for="name" class="form-label">Ad soyad</label>
    <input type="text"
           id="name"
           name="name"
           value="{{ old('name', $user->name) }}"
           maxlength="255"
           required
           autocomplete="off"
           @class(['form-control', 'is-invalid' => $errors->has('name')])
           @error('name') aria-describedby="name-error" @enderror>
    @error('name')
        <div id="name-error" class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="mb-3">
    <label for="email" class="form-label">E-posta</label>
    <input type="email"
           id="email"
           name="email"
           value="{{ old('email', $user->email) }}"
           maxlength="255"
           required
           autocomplete="off"
           @class(['form-control', 'is-invalid' => $errors->has('email')])
           aria-describedby="email-help @error('email') email-error @enderror">
    @error('email')
        <div id="email-error" class="invalid-feedback">{{ $message }}</div>
    @enderror
    <div id="email-help" class="form-text">Kişi bu adresle giriş yapar.</div>
</div>

<div class="mb-0">
    <label for="role" class="form-label">Rol</label>
    <select id="role"
            name="role"
            required
            @class(['form-select', 'is-invalid' => $errors->has('role')])
            aria-describedby="role-help @error('role') role-error @enderror">
        @foreach ($roles as $role)
            <option value="{{ $role->value }}" @selected(old('role', $user->role?->value ?? \App\Enums\UserRole::Operator->value) === $role->value)>{{ $role->label() }}</option>
        @endforeach
    </select>
    @error('role')
        <div id="role-error" class="invalid-feedback">{{ $message }}</div>
    @enderror
    <div id="role-help" class="form-text">
        @if ($isSelf ?? false)
            Kendi yönetici rolünüzü kaldıramazsınız.
        @else
            Operatör kayıt açar ve kendi işindeki adımları ilerletir; yönetici ayrıca tanımları yönetir, raporları görür ve açık kayıtları iptal edebilir.
        @endif
    </div>
</div>

@extends('layouts.guest')

@section('title', 'Giriş')

@section('content')
    <div class="card login-card">
        <div class="card-body login-card-body">
            <p class="card-kicker">Hoş geldiniz</p>
            <h1 class="login-card__title">Giriş yap</h1>
            <p class="login-box-msg">Devam etmek için e-posta adresiniz ve şifrenizle giriş yapın.</p>

            <form method="POST" action="{{ route('login.store') }}" class="login-form">
                @csrf

                <div class="login-form__field">
                    <label for="email" class="form-label">E-posta</label>
                    <input type="email"
                           id="email"
                           name="email"
                           value="{{ old('email') }}"
                           @class(['form-control', 'is-invalid' => $errors->has('email')])
                           @error('email') aria-describedby="email-error" aria-invalid="true" @enderror
                           autocomplete="username"
                           required
                           autofocus>
                    @error('email')
                        <div id="email-error" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="login-form__field">
                    <label for="password" class="form-label">Şifre</label>
                    <input type="password"
                           id="password"
                           name="password"
                           @class(['form-control', 'is-invalid' => $errors->has('password')])
                           @error('password') aria-describedby="password-error" aria-invalid="true" @enderror
                           autocomplete="current-password"
                           required>
                    @error('password')
                        <div id="password-error" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="login-form__footer">
                    <div class="form-check">
                        <input type="checkbox"
                               id="remember"
                               name="remember"
                               value="1"
                               class="form-check-input"
                               @checked(old('remember'))>
                        <label for="remember" class="form-check-label">Beni hatırla</label>
                    </div>

                    <button type="submit" class="btn btn-primary login-form__submit">Giriş yap</button>
                </div>
            </form>
        </div>

        @if ($demoAccounts)
            <div class="card-footer demo-accounts">
                <p class="demo-accounts__title">Demo hesapları · şifre <code>{{ $demoPassword }}</code></p>
                <ul class="demo-accounts__list list-unstyled mb-0">
                    @foreach ($demoAccounts as $account)
                        <li @class(['demo-accounts__item', 'demo-accounts__item--inactive' => ! $account['active']])>
                            <code>{{ $account['email'] }}</code>
                            <span class="demo-accounts__role">{{ $account['role']->label() }}{{ $account['active'] ? '' : ', pasif' }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
@endsection

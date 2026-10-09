@extends('layouts.guest')

@section('title', 'Giriş')

@section('content')
    <div class="card login-card">
        <div class="card-body login-card-body">
            <p class="login-box-msg">Devam etmek için giriş yapın</p>

            <form method="POST" action="{{ route('login.store') }}" class="login-form">
                @csrf

                <div class="mb-3">
                    <label for="email" class="form-label">E-posta</label>
                    <div class="input-group has-validation">
                        <input type="email"
                               id="email"
                               name="email"
                               value="{{ old('email') }}"
                               @class(['form-control', 'is-invalid' => $errors->has('email')])
                               @error('email') aria-describedby="email-error" aria-invalid="true" @enderror
                               autocomplete="username"
                               required
                               autofocus>
                        <span class="input-group-text"><i class="bi bi-envelope" aria-hidden="true"></i></span>
                        @error('email')
                            <div id="email-error" class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">Şifre</label>
                    <div class="input-group has-validation">
                        <input type="password"
                               id="password"
                               name="password"
                               @class(['form-control', 'is-invalid' => $errors->has('password')])
                               @error('password') aria-describedby="password-error" aria-invalid="true" @enderror
                               autocomplete="current-password"
                               required>
                        <span class="input-group-text"><i class="bi bi-lock-fill" aria-hidden="true"></i></span>
                        @error('password')
                            <div id="password-error" class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="row align-items-center">
                    <div class="col-7">
                        <div class="form-check">
                            <input type="checkbox"
                                   id="remember"
                                   name="remember"
                                   value="1"
                                   class="form-check-input"
                                   @checked(old('remember'))>
                            <label for="remember" class="form-check-label">Beni hatırla</label>
                        </div>
                    </div>
                    <div class="col-5">
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">Giriş yap</button>
                        </div>
                    </div>
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

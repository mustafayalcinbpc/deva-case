<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · {{ config('app.name') }}</title>
    @include('layouts.partials.theme-init')
    @fonts
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
</head>
<body class="login-page">
<div class="login-page__theme">
    @include('layouts.partials.theme-toggle', ['class' => 'btn btn-icon'])
</div>

<main class="login-box">
    <div class="login-logo">
        @include('layouts.partials.brand', ['link' => false])
    </div>

    @include('layouts.partials.flash')
    @yield('content')
</main>
</body>
</html>

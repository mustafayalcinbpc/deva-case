<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · {{ config('app.name') }}</title>
    @fonts
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
</head>
<body class="login-page bg-body-secondary">
<div class="login-box">
    <div class="login-logo">
        <span class="app-brand">{{ config('app.name') }}</span>
    </div>

    @include('layouts.partials.flash')
    @yield('content')
</div>
</body>
</html>

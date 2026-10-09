<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') · {{ config('app.name') }}</title>
    @include('layouts.partials.theme-init')
    @fonts
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
<a href="#main-content" class="skip-link">İçeriğe geç</a>
<div class="app-wrapper">
    @include('layouts.partials.header')
    @include('layouts.partials.sidebar')

    <main class="app-main" id="main-content" tabindex="-1">
        <div class="app-content-header">
            <div class="container-fluid app-page-header">
                <div class="app-page-header__text">
                    <h1 class="app-page-title mb-0">@yield('page-title')</h1>
                    @hasSection('page-subtitle')
                        <p class="app-page-subtitle mb-0">@yield('page-subtitle')</p>
                    @endif
                </div>
                @hasSection('page-actions')
                    <div class="app-page-actions">@yield('page-actions')</div>
                @endif
            </div>
        </div>

        <div class="app-content">
            <div class="container-fluid">
                @include('layouts.partials.flash')
                @yield('content')
            </div>
        </div>
    </main>

    @include('layouts.partials.footer')
</div>
</body>
</html>

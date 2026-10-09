<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') · {{ config('app.name') }}</title>
    @fonts
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
<div class="app-wrapper">
    @include('layouts.partials.header')
    @include('layouts.partials.sidebar')

    <main class="app-main">
        <div class="app-content-header">
            <div class="container-fluid">
                <h1 class="app-page-title h3 mb-0">@yield('page-title')</h1>
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

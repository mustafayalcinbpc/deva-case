{{--
    Üst bar: menü düğmesi (yalnızca dar ekranda), breadcrumb, tema düğmesi, bildirim zili ve
    kullanıcı menüsü. Breadcrumb menüdeki etkin öğeden üretilir: uygulama / bölüm / öğe / sayfa.
--}}
@php
    $trail = app(\App\View\Components\SidebarMenu::class)->trail();
    // Bölüm içeriği Blade tarafından zaten kaçışlanmış HTML'dir.
    $pageTitle = trim($__env->yieldContent('title'));
    $trailItem = $trail['item'];
    $showItemLink = $trailItem !== null && e($trailItem['label']) !== $pageTitle;
@endphp

<header class="app-header navbar navbar-expand bg-body">
    <div class="container-fluid">
        <button type="button" class="btn btn-icon app-header__menu-toggle" data-lte-toggle="sidebar" aria-label="Menüyü aç/kapat">
            <i class="bi bi-list" aria-hidden="true"></i>
        </button>

        <nav class="app-breadcrumb" aria-label="Konum">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dijital Temizlik</a></li>
                @if ($trail['section'])
                    <li class="breadcrumb-item">{{ $trail['section'] }}</li>
                @endif
                @if ($showItemLink)
                    <li class="breadcrumb-item"><a href="{{ route($trailItem['route']) }}">{{ $trailItem['label'] }}</a></li>
                @endif
                <li class="breadcrumb-item active" aria-current="page">{!! $pageTitle !!}</li>
            </ol>
        </nav>

        <ul class="navbar-nav ms-auto app-header__actions">
            <li class="nav-item">
                @include('layouts.partials.theme-toggle')
            </li>
            <li class="nav-item">
                <x-notification-bell />
            </li>
            <li class="nav-item dropdown user-menu">
                <a href="#" class="nav-link user-menu__toggle" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Kullanıcı menüsü">
                    @include('layouts.partials.avatar', ['name' => auth()->user()->name])
                    <span class="user-menu__name d-none d-md-inline">{{ auth()->user()->name }}</span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li class="user-menu__header">
                        <span class="user-menu__header-name">{{ auth()->user()->name }}</span>
                        <span class="user-menu__header-role">{{ auth()->user()->role->label() }}</span>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form method="POST" action="{{ route('logout') }}" class="user-menu__logout">
                            @csrf
                            <button type="submit" class="dropdown-item">
                                <i class="bi bi-box-arrow-right" aria-hidden="true"></i> Çıkış yap
                            </button>
                        </form>
                    </li>
                </ul>
            </li>
        </ul>
    </div>
</header>

<aside class="app-sidebar" aria-label="Kenar çubuğu">
    <div class="sidebar-brand">
        @include('layouts.partials.brand')
    </div>

    <div class="sidebar-wrapper">
        <nav aria-label="Ana menü">
            <x-sidebar-menu />
        </nav>
    </div>

    @auth
        <div class="sidebar-user">
            @include('layouts.partials.avatar', ['name' => auth()->user()->name])
            <div class="sidebar-user__text">
                <span class="sidebar-user__name">{{ auth()->user()->name }}</span>
                <span class="sidebar-user__role">{{ auth()->user()->role->label() }}</span>
            </div>
            <form method="POST" action="{{ route('logout') }}" class="sidebar-user__logout">
                @csrf
                <button type="submit" class="btn btn-icon btn-sm btn-ghost" aria-label="Çıkış yap" title="Çıkış yap">
                    <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
                </button>
            </form>
        </div>
    @endauth
</aside>

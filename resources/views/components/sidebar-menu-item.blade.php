{{-- Sidebar menüsünün bir bağlantısı (sidebar-menu); $item: label, icon, route, active. --}}
<li class="nav-item">
    <a href="{{ route($item['route']) }}" @class(['nav-link', 'active' => $item['active']]) @if ($item['active']) aria-current="page" @endif>
        <i class="nav-icon bi {{ $item['icon'] }}" aria-hidden="true"></i>
        <p>{{ $item['label'] }}</p>
    </a>
</li>

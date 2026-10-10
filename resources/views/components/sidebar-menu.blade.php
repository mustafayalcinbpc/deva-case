{{--
    Sidebar menüsü (SidebarMenu): tek öğeler ve aşağı doğru açılan gruplar. Grup başlığı bir
    düğmedir; AdminLTE treeview açar/kapar ve aria-expanded'ı günceller. Etkin öğeyi içeren grup
    açık gelir (menu-open). Mini menüde (yalnızca ikonlar) grup ikonları görünür.
--}}
<ul class="nav sidebar-menu flex-column" data-lte-toggle="treeview" data-accordion="false">
    @foreach ($items as $entry)
        @if (isset($entry['items']))
            <li @class(['nav-item', 'nav-group', 'menu-open' => $entry['open']])>
                <button type="button" @class(['nav-link', 'nav-group__toggle', 'nav-group__toggle--current' => $entry['open']])
                        aria-expanded="{{ $entry['open'] ? 'true' : 'false' }}" aria-controls="menu-group-{{ $loop->index }}">
                    <i class="nav-icon bi {{ $entry['icon'] }}" aria-hidden="true"></i>
                    <p>
                        {{ $entry['label'] }}
                        <i class="nav-arrow bi bi-chevron-right" aria-hidden="true"></i>
                    </p>
                </button>
                <ul class="nav nav-treeview" id="menu-group-{{ $loop->index }}">
                    @foreach ($entry['items'] as $item)
                        @include('components.sidebar-menu-item', ['item' => $item])
                    @endforeach
                </ul>
            </li>
        @else
            @include('components.sidebar-menu-item', ['item' => $entry])
        @endif
    @endforeach
</ul>

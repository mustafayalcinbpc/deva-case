<ul class="nav sidebar-menu flex-column" data-lte-toggle="treeview" data-accordion="false">
    @foreach ($items as $item)
        @if (isset($item['header']))
            <li class="nav-header">{{ $item['header'] }}</li>
        @else
            <li class="nav-item">
                <a href="{{ route($item['route']) }}" @class(['nav-link', 'active' => $item['active']]) @if ($item['active']) aria-current="page" @endif>
                    <i class="nav-icon bi {{ $item['icon'] }}" aria-hidden="true"></i>
                    <p>{{ $item['label'] }}</p>
                </a>
            </li>
        @endif
    @endforeach
</ul>

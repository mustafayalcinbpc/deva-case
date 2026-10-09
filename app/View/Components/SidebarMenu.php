<?php

namespace App\View\Components;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Component;

/**
 * config/menu.php'deki öğelerden kullanıcının rolüne uygun olanları gösterir ve
 * bulunulan sayfayı işaretler. Altında öğe kalmayan başlıklar gizlenir.
 */
class SidebarMenu extends Component
{
    /** @var list<array<string, mixed>> */
    public array $items;

    public function __construct(Request $request)
    {
        $this->items = $this->build(config('menu', []), Auth::user(), $request);
    }

    public function render(): View
    {
        return view('components.sidebar-menu');
    }

    /**
     * @param  list<array<string, mixed>>  $config
     * @return list<array<string, mixed>>
     */
    private function build(array $config, ?User $user, Request $request): array
    {
        $visible = array_values(array_filter(
            $config,
            fn (array $item) => ! isset($item['roles']) || ($user && in_array($user->role->value, $item['roles'], true)),
        ));

        $items = [];

        foreach ($visible as $index => $item) {
            if (isset($item['header'])) {
                $next = $visible[$index + 1] ?? null;

                if ($next !== null && ! isset($next['header'])) {
                    $items[] = $item;
                }

                continue;
            }

            $items[] = $item + ['active' => $request->routeIs($item['active'] ?? $item['route'])];
        }

        return $items;
    }
}

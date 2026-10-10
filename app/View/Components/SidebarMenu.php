<?php

namespace App\View\Components;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Component;

/**
 * config/menu.php'deki öğe ve gruplardan kullanıcının rolüne uygun olanları gösterir ve bulunulan
 * sayfayı işaretler. Görünen öğesi kalmayan grup gizlenir; etkin öğeyi içeren grup açık gelir.
 * Üst bardaki breadcrumb da aynı listeden üretilir (trail()).
 */
class SidebarMenu extends Component
{
    /**
     * Tek öğe: label, icon, route, active. Grup: label, icon, items (öğeler), open.
     *
     * @var list<array<string, mixed>>
     */
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
     * Bulunulan sayfanın menüdeki yeri: grup (varsa) ve etkin öğe.
     *
     * @return array{section: ?string, item: ?array<string, mixed>}
     */
    public function trail(): array
    {
        foreach ($this->items as $entry) {
            if (isset($entry['items'])) {
                foreach ($entry['items'] as $item) {
                    if ($item['active']) {
                        return ['section' => $entry['label'], 'item' => $item];
                    }
                }

                continue;
            }

            if ($entry['active']) {
                return ['section' => null, 'item' => $entry];
            }
        }

        return ['section' => null, 'item' => null];
    }

    /**
     * @param  list<array<string, mixed>>  $config
     * @return list<array<string, mixed>>
     */
    private function build(array $config, ?User $user, Request $request): array
    {
        $entries = [];

        foreach ($config as $entry) {
            if (! $this->allows($entry, $user)) {
                continue;
            }

            if (! isset($entry['group'])) {
                if ($item = $this->item($entry, $user, $request)) {
                    $entries[] = $item;
                }

                continue;
            }

            $items = array_values(array_filter(array_map(
                fn (array $item) => $this->item($item, $user, $request),
                $entry['items'] ?? [],
            )));

            if ($items !== []) {
                $entries[] = [
                    'label' => $entry['group'],
                    'icon' => $entry['icon'] ?? 'bi-folder',
                    'items' => $items,
                    'open' => in_array(true, array_column($items, 'active'), true),
                ];
            }
        }

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function item(array $item, ?User $user, Request $request): ?array
    {
        if (! $this->allows($item, $user) || ! Route::has($item['route'])) {
            return null;
        }

        return ['active' => $request->routeIs(...(array) ($item['active'] ?? $item['route']))] + $item;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function allows(array $entry, ?User $user): bool
    {
        return ! isset($entry['roles']) || ($user !== null && in_array($user->role->value, $entry['roles'], true));
    }
}

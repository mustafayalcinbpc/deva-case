<?php

namespace App\View\Components;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Component;

/**
 * config/menu.php'deki öğelerden kullanıcının rolüne uygun olanları gösterir ve
 * bulunulan sayfayı işaretler. Altında öğe kalmayan başlıklar gizlenir.
 * Üst bardaki breadcrumb da aynı listeden üretilir (trail()).
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
     * Bulunulan sayfanın menüdeki yeri: bölüm başlığı (varsa) ve etkin öğe.
     *
     * @return array{section: ?string, item: ?array<string, mixed>}
     */
    public function trail(): array
    {
        $section = null;

        foreach ($this->items as $item) {
            if (isset($item['header'])) {
                $section = $item['header'];

                continue;
            }

            if ($item['active']) {
                return ['section' => $section, 'item' => $item];
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
        $visible = array_values(array_filter(
            $config,
            fn (array $item) => (! isset($item['roles']) || ($user && in_array($user->role->value, $item['roles'], true)))
                && (! isset($item['route']) || Route::has($item['route'])),
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

            $items[] = ['active' => $request->routeIs(...(array) ($item['active'] ?? $item['route']))] + $item;
        }

        return $items;
    }
}

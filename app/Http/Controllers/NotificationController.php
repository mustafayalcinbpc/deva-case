<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Kullanıcının kendi veritabanı bildirimleri. Her sorgu oturumdaki kullanıcının bildirimleriyle
 * sınırlıdır; başkasının bildirimi 404 döner.
 */
class NotificationController extends Controller
{
    /** Liste filtresi: okunmamış, okunmuş; boşsa hepsi. */
    private const FILTERS = ['unread' => 'Okunmamış', 'read' => 'Okunmuş'];

    public function index(Request $request): View
    {
        $filter = $request->query('filter');
        $filter = is_string($filter) && isset(self::FILTERS[$filter]) ? $filter : null;

        $notifications = $request->user()->notifications()
            ->when($filter === 'unread', fn ($query) => $query->whereNull('read_at'))
            ->when($filter === 'read', fn ($query) => $query->whereNotNull('read_at'))
            ->paginate(20)
            ->withQueryString();

        return view('notifications.index', [
            'notifications' => $notifications,
            'filter' => $filter,
            'filters' => self::FILTERS,
        ]);
    }

    /**
     * Bildirimi okundu işaretler ve bağlantısına gider; bağlantı yoksa listeye döner.
     */
    public function open(Request $request, string $notification): RedirectResponse
    {
        $notification = $request->user()->notifications()->findOrFail($notification);
        $notification->markAsRead();

        return redirect()->to($this->safeUrl($request, $notification->data['url'] ?? null) ?? route('notifications.index'));
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back(fallback: route('notifications.index'))->with('status', 'Bütün bildirimler okundu olarak işaretlendi.');
    }

    /**
     * Yalnızca uygulama içi adreslere yönlendirilir: göreli yol ya da uygulamanın kendi alan adı.
     */
    private function safeUrl(Request $request, mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        if (preg_match('#^/(?![/\\\\])#', $url) === 1) {
            return $url;
        }

        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $allowedHosts = [$request->getHost(), parse_url((string) config('app.url'), PHP_URL_HOST)];

        return in_array($scheme, ['http', 'https'], true) && in_array($host, $allowedHosts, true) ? $url : null;
    }
}

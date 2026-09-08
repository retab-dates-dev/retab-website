<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Admin\SettingController;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\CartService;
use App\Services\WhatsApp\WhatsAppGateway;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        return array_merge(parent::share($request), [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $request->user(),
                // Editors: their resolved section→action grants (drives admin nav
                // visibility + client-side gating). Null for admins = full access.
                'permissions' => $request->user()?->isEditor() ? $request->user()->resolvedPermissions() : null,
            ],
            'locale' => session('locale', 'ar'),
            // Global toggle for the admin "How it works" attention beam (staff only;
            // per-session dismissal is client-side). Default on when unset.
            'helpPulse' => fn () => $request->user()?->isStaff()
                ? Setting::get('admin_help_pulse', '1') !== '0'
                : null,
            // Null while unset → the Turnstile widget renders nothing (dev).
            'turnstileSiteKey' => config('services.turnstile.site_key'),
            // Whether WhatsApp can actually deliver a sign-in code. Drives BOTH the
            // navbar's signed-out account destination and the whatsapp-login page,
            // so the storefront never offers a door that cannot open.
            //
            // 🔑 Config-driven rather than a hardcoded temporary: when the client's
            // Meta verification clears, setting WHATSAPP_DRIVER=cloud (with a token)
            // lights the whole flow back up with no code change and nothing to
            // remember to revert on launch day.
            'whatsappAuth' => fn () => app(WhatsAppGateway::class)->isLive(),
            // Default social-share card. Shared rather than hardcoded client-side
            // because og:image MUST be absolute — crawlers do not resolve relative
            // paths — so it has to be built from APP_URL on the server. JPEG, not
            // the WebP twin: WhatsApp's link-preview crawler is unreliable with
            // WebP, and WhatsApp is this store's main channel.
            'ogImage' => url('/og-image.jpg'),
            // Storefront nav tree (parents + active children) for the navbar.
            // Closure → only resolved for Inertia responses, not every request.
            'navCategories' => fn () => Category::query()
                ->whereNull('parent_id')
                ->where('is_active', true)
                ->with(['children' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])
                ->orderBy('sort_order')
                ->get()
                ->map(fn (Category $c) => [
                    'id' => $c->id,
                    'name_ar' => $c->name_ar,
                    'name_en' => $c->name_en,
                    'slug' => $c->slug,
                    'children' => $c->children->map(fn (Category $child) => [
                        'id' => $child->id,
                        'name_ar' => $child->name_ar,
                        'name_en' => $child->name_en,
                        'slug' => $child->slug,
                    ])->values(),
                ])->values(),
            'cart' => [
                'count' => app(CartService::class)->count(),
            ],
            // Whether any active discounted product exists → drives the storefront
            // "Offers" nav visibility (hidden when there are none). Closure: a cheap
            // EXISTS resolved only for full Inertia page loads.
            //
            // ⚠️ `notInRunningEvent()` is NOT optional here, and this is the call
            // site easiest to forget: it must match the catalogue's `on_sale` filter
            // exactly, or the nav shows an Offers link whose page is empty because
            // every discounted product happens to be inside a running store event.
            'hasOffers' => fn () => Product::where('is_active', true)->onSale()->notInRunningEvent()->exists(),
            // Footer/contact block, admin-editable via settings (falls back to
            // FOOTER_DEFAULTS when a key is unset). Closure → resolved only for
            // Inertia page responses; one batched query.
            'footer' => fn () => $this->footerSettings(),
            // Per-user saved table column widths (resizable admin tables).
            'tablePrefs' => fn () => $request->user()?->isStaff()
                ? (object) ($request->user()->ui_preferences['tableWidths'] ?? [])
                : null,
            'flash' => [
                'success' => $success = $request->session()->get('success'),
                'error' => $error = $request->session()->get('error'),
                // A per-response nonce, set only when a message exists, so the client
                // shows a fresh toast even when two consecutive actions flash the SAME
                // text (an identical string wouldn't change a value-compared effect dep).
                'id' => ($success || $error) ? (string) Str::uuid() : null,
                // Structured revert-conflict (fields + the blocking change to
                // undo first) → rendered as a banner with a "take me to it" link.
                'revertConflict' => $request->session()->get('revertConflict'),
            ],
            // Flashed "undo last save" pointer → the immediate toast after a
            // tracked admin save (staff only; the persistent per-section button
            // is passed as a page prop instead).
            'undo' => fn () => $request->user()?->isStaff() ? $request->session()->get('undo') : null,
            // Admin notification bell (staff only): unread count + latest items.
            // Structured `data` is rendered client-side so it follows the admin
            // language toggle. Closure → resolved only for Inertia page responses.
            'notifications' => fn () => $request->user()?->isStaff()
                ? $this->adminNotifications($request->user())
                : null,
        ]);
    }

    /**
     * Recent admin-bell notifications for a staff user: unread count + the latest
     * 10 rows (structured `data` payloads). Two cheap indexed queries.
     *
     * @return array{unread:int, items:list<array<string, mixed>>}
     */
    private function adminNotifications(User $user): array
    {
        $items = $user->notifications()->latest()->limit(10)->get()
            ->map(fn ($n) => [
                'id' => $n->id,
                'read' => $n->read_at !== null,
                'created_at' => $n->created_at?->toIso8601String(),
                'data' => $n->data,
            ])->all();

        return [
            'unread' => $user->unreadNotifications()->count(),
            'items' => $items,
        ];
    }

    /**
     * Footer/contact values for the storefront: stored setting where present,
     * otherwise the FOOTER_DEFAULTS fallback. One batched query over the keys.
     *
     * @return array<string, string>
     */
    private function footerSettings(): array
    {
        $defaults = SettingController::FOOTER_DEFAULTS;
        $stored = Setting::query()
            ->whereIn('key', array_keys($defaults))
            ->pluck('value', 'key');

        return collect($defaults)
            ->map(fn (string $default, string $key) => filled($stored->get($key)) ? $stored->get($key) : $default)
            ->all();
    }
}

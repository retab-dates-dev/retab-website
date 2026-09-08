<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StoreEvent;
use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Store events — named, time-boxed homepage campaigns ("اليوم الوطني السعودي").
 *
 * Two screens: the list (what is running, scheduled and over) and one event's own
 * page, where its offers are attached, ordered, badged and given artwork.
 *
 * 🔑 The offers live on the pivot, not on the products, so nothing here ever edits
 * a product. An event can be built and torn down without touching the catalogue,
 * and the same product can carry a different badge in next year's campaign.
 * The one thing an event does NOT set is the price: that is the product's own
 * `sale_price` + window, which already exists and already expires on its own.
 */
class StoreEventController extends Controller
{
    public function index()
    {
        return Inertia::render('admin/store-events/index', [
            'events' => StoreEvent::withCount('products')
                ->orderByDesc('starts_at')
                ->paginate($this->perPage(request(), 20))
                ->through(fn (StoreEvent $e) => $this->row($e)),
            'accentPresets' => StoreEvent::ACCENT_PRESETS,
        ]);
    }

    /** One event: its own fields, its offers in order, and the pool to add from. */
    public function show(StoreEvent $storeEvent)
    {
        $storeEvent->load(['products' => fn ($q) => $q->with('images')]);

        return Inertia::render('admin/store-events/show', [
            'event' => $this->row($storeEvent) + [
                'offers' => $storeEvent->products->map(fn (Product $p) => [
                    'product_id' => $p->id,
                    'name_ar' => $p->name_ar,
                    'name_en' => $p->name_en,
                    'sku' => $p->sku,
                    'slug' => $p->slug,
                    'price' => (float) $p->price,
                    'sale_price' => $p->sale_price !== null ? (float) $p->sale_price : null,
                    'on_sale' => $p->isOnSale(),
                    // Surfaced so the admin can see, on this page, that an offer's
                    // discount is only scheduled or has already lapsed — the
                    // commonest way an event looks wrong on the storefront.
                    'sale_state' => $p->sale_price !== null ? $p->saleStatus() : null,
                    'sale_ends_at' => $p->sale_ends_at?->toDateTimeString(),
                    'is_active' => (bool) $p->is_active,
                    'badge_ar' => $p->pivot->badge_ar,
                    'badge_en' => $p->pivot->badge_en,
                    'banner_image' => Media::url($p->pivot->banner_image, 'card'),
                    'has_banner' => $p->pivot->banner_image !== null,
                    // What the storefront card will actually show, which is the
                    // banner when there is one and the product photo otherwise.
                    'preview' => Media::url($p->pivot->banner_image ?: $p->primaryImage()?->path, 'card'),
                    'sort_order' => (int) $p->pivot->sort_order,
                ])->all(),
            ],
            // The whole live catalogue, minus what is already in this event. ~90
            // rows, so the picker filters in the browser rather than round-tripping
            // per keystroke — the same call the storefront typeahead makes.
            'pool' => Product::where('is_active', true)
                ->whereNotIn('id', $storeEvent->products->pluck('id'))
                ->with('images')
                ->orderBy('name_ar')
                ->get()
                ->map(fn (Product $p) => [
                    'id' => $p->id,
                    'name_ar' => $p->name_ar,
                    'name_en' => $p->name_en,
                    'sku' => $p->sku,
                    'price' => (float) $p->price,
                    'on_sale' => $p->isOnSale(),
                    'image' => Media::url($p->primaryImage()?->path, 'thumb'),
                ])->all(),
            'accentPresets' => StoreEvent::ACCENT_PRESETS,
        ]);
    }

    public function store(Request $request)
    {
        $event = StoreEvent::create($this->validated($request));

        return redirect()->route('admin.store-events.show', $event)
            ->with('success', __('messages.admin.store_event_saved'));
    }

    public function update(Request $request, StoreEvent $storeEvent)
    {
        $storeEvent->update($this->validated($request));

        return back()->with('success', __('messages.admin.store_event_saved'));
    }

    /** Quick pause/resume from the list — flips is_active without opening the editor. */
    public function toggle(StoreEvent $storeEvent)
    {
        $storeEvent->update(['is_active' => ! $storeEvent->is_active]);

        return back()->with('success', __($storeEvent->is_active
            ? 'messages.admin.store_event_resumed'
            : 'messages.admin.store_event_paused'));
    }

    public function destroy(StoreEvent $storeEvent)
    {
        // Uploaded artwork belongs to the event, so it goes with it — otherwise
        // every deleted campaign leaves its banners orphaned in R2 with nothing
        // left pointing at them.
        foreach ($storeEvent->products as $product) {
            Media::delete($product->pivot->banner_image);
        }

        $storeEvent->delete();

        return redirect()->route('admin.store-events.index')
            ->with('success', __('messages.admin.store_event_deleted'));
    }

    /** Add a product to the event, appended after whatever is already there. */
    public function attachOffer(Request $request, StoreEvent $storeEvent)
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at')],
        ]);

        // syncWithoutDetaching rather than attach: the unique index would throw on a
        // double-submit, and adding a product already in the event is a no-op, not
        // an error worth showing anyone.
        $storeEvent->products()->syncWithoutDetaching([
            $data['product_id'] => ['sort_order' => (int) $storeEvent->products()->max('event_product.sort_order') + 1],
        ]);

        return back()->with('success', __('messages.admin.store_event_offer_added'));
    }

    /** Badge text for one offer. The artwork has its own endpoint (multipart). */
    public function updateOffer(Request $request, StoreEvent $storeEvent, Product $product)
    {
        $data = $request->validate([
            'badge_ar' => ['nullable', 'string', 'max:60'],
            'badge_en' => ['nullable', 'string', 'max:60'],
        ]);

        $this->assertAttached($storeEvent, $product);
        $storeEvent->products()->updateExistingPivot($product->id, $data);

        return back()->with('success', __('messages.admin.store_event_offer_saved'));
    }

    public function detachOffer(StoreEvent $storeEvent, Product $product)
    {
        $this->assertAttached($storeEvent, $product);

        Media::delete($storeEvent->products()->find($product->id)?->pivot->banner_image);
        $storeEvent->products()->detach($product->id);

        return back()->with('success', __('messages.admin.store_event_offer_removed'));
    }

    /**
     * The offer's own artwork.
     *
     * ⚠️ Its own POST endpoint rather than a field on updateOffer, for the same
     * reason product images have one: a PUT/PATCH carrying multipart is not parsed
     * by PHP, so the file would silently arrive empty.
     */
    public function uploadOfferBanner(Request $request, StoreEvent $storeEvent, Product $product)
    {
        $request->validate([
            'banner' => ['required', 'file', 'image', 'mimes:'.implode(',', Media::IMAGE_EXTENSIONS),
                'mimetypes:'.implode(',', Media::IMAGE_MIMES), 'max:4096'],
        ]);

        $this->assertAttached($storeEvent, $product);

        $existing = $storeEvent->products()->find($product->id)?->pivot->banner_image;
        $path = Media::storeImage($request->file('banner'), "events/{$storeEvent->id}");
        $storeEvent->products()->updateExistingPivot($product->id, ['banner_image' => $path]);

        // Only after the replacement is safely stored — deleting first would lose
        // the old artwork if the upload then failed.
        Media::delete($existing);

        return back()->with('success', __('messages.admin.store_event_offer_saved'));
    }

    /** Drop the artwork; the card falls back to the product's own photo. */
    public function deleteOfferBanner(StoreEvent $storeEvent, Product $product)
    {
        $this->assertAttached($storeEvent, $product);

        Media::delete($storeEvent->products()->find($product->id)?->pivot->banner_image);
        $storeEvent->products()->updateExistingPivot($product->id, ['banner_image' => null]);

        return back()->with('success', __('messages.admin.store_event_offer_saved'));
    }

    /** Whole-list reorder: the ids in the order the admin dragged them into. */
    public function reorderOffers(Request $request, StoreEvent $storeEvent)
    {
        $data = $request->validate([
            'product_ids' => ['required', 'array'],
            'product_ids.*' => ['integer'],
        ]);

        // Only ids already in this event, so a stale page cannot attach a product
        // through the reorder endpoint. One transaction, so a partial reorder can
        // never leave two offers claiming the same position.
        $attached = $storeEvent->products()->pluck('products.id')->all();

        DB::transaction(function () use ($storeEvent, $data, $attached) {
            foreach (array_values(array_intersect($data['product_ids'], $attached)) as $i => $id) {
                $storeEvent->products()->updateExistingPivot($id, ['sort_order' => $i]);
            }
        });

        return back();
    }

    /** @return array<string, mixed> */
    private function row(StoreEvent $event): array
    {
        return [
            'id' => $event->id,
            'name_ar' => $event->name_ar,
            'name_en' => $event->name_en,
            'subtitle_ar' => $event->subtitle_ar,
            'subtitle_en' => $event->subtitle_en,
            'starts_at' => $event->starts_at->toDateTimeString(),
            'ends_at' => $event->ends_at->toDateTimeString(),
            'accent_color' => $event->accent_color,
            'accent' => $event->accent(),
            'is_active' => $event->is_active,
            'sort_order' => $event->sort_order,
            'state' => $event->state(),
            'offer_count' => $event->products_count ?? $event->products()->count(),
        ];
    }

    /**
     * A product reached through an event's own URL must actually belong to it —
     * otherwise these endpoints would edit or delete pivot rows on another event
     * and, worse, `Media::delete` artwork that is still in use.
     */
    private function assertAttached(StoreEvent $storeEvent, Product $product): void
    {
        abort_unless($storeEvent->products()->whereKey($product->id)->exists(), 404);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'subtitle_ar' => ['nullable', 'string', 'max:255'],
            'subtitle_en' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            // Required on both sides, unlike a product's optional sale window: an
            // event with no end would sit on the homepage indefinitely.
            'ends_at' => ['required', 'date', 'after:starts_at'],
            // Presets are offered in the UI; the column takes any hex so a one-off
            // brand colour never needs a migration.
            'accent_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}

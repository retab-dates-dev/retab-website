<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EventHeroBanner;
use App\Models\Product;
use App\Models\StoreEvent;
use App\Services\ChangeLog\ChangeLogService;
use App\Support\Media;
use App\Support\ProductCodes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Inertia\Inertia;

/**
 * Store events — named, time-boxed homepage campaigns ("اليوم الوطني السعودي").
 *
 * Two screens: the list (what is running, scheduled and over) and one event's own
 * page, where the whole campaign is assembled: its offers (attached from the
 * catalogue, or created on the spot as time-boxed products), their order, badges
 * and artwork, and the event's own homepage hero banners.
 *
 * 🔑 An ATTACHED offer's campaign data lives on the pivot, not on the product, so
 * attaching never edits a product: the same product can carry a different badge in
 * next year's campaign. A CREATED offer is different by nature — it is a product
 * that exists only for this campaign, so it is born with `available_until` set to
 * the event's end and leaves the store on its own when the event does.
 *
 * The one thing an event does NOT set is a discount: that is the product's own
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

    /** One event: its own fields, its offers in order, its hero banners, and the pool to add from. */
    public function show(StoreEvent $storeEvent)
    {
        $storeEvent->load(['products' => fn ($q) => $q->with('images'), 'heroBanners.product']);

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
                    'available_until' => $p->available_until?->toDateTimeString(),
                    'stock' => (int) $p->stock,
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
                'banners' => $storeEvent->heroBanners->map(function (EventHeroBanner $b) use ($storeEvent) {
                    // Already loaded above; set so state() reads it without a query per row.
                    $b->setRelation('event', $storeEvent);

                    return [
                        'id' => $b->id,
                        'image' => Media::url($b->image, 'card'),
                        'image_mobile' => Media::url($b->image_mobile, 'card'),
                        'product_id' => $b->product_id,
                        'alt_ar' => $b->alt_ar,
                        'alt_en' => $b->alt_en,
                        'starts_at' => $b->starts_at?->toDateTimeString(),
                        'ends_at' => $b->ends_at?->toDateTimeString(),
                        'is_active' => $b->is_active,
                        'state' => $b->state(),
                    ];
                })->all(),
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
        $oldEnd = $storeEvent->ends_at->copy();
        $storeEvent->update($this->validated($request));

        // Offers born for this event end WITH it. When its end moves, carry theirs
        // along — but only the ones still pinned to the old end, so an offer whose
        // date an admin changed by hand keeps that choice. (An attached catalogue
        // product has no available_until at all, so it is never touched.)
        if (! $oldEnd->equalTo($storeEvent->ends_at)) {
            $storeEvent->products()
                ->where('products.available_until', $oldEnd)
                ->get()
                ->each(fn (Product $p) => $p->update(['available_until' => $storeEvent->ends_at]));
        }

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
        // left pointing at them. The rows cascade; the files do not.
        foreach ($storeEvent->products as $product) {
            Media::delete($product->pivot->banner_image);
        }
        foreach ($storeEvent->heroBanners as $banner) {
            Media::delete($banner->image);
            Media::delete($banner->image_mobile);
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

    /**
     * Create a brand-new, time-boxed product for this event and attach it.
     *
     * For campaign-only products (a National Day bundle) that have no life in the
     * catalogue before or after the campaign. It lands in the Special Offers
     * category, gets the next `RTB-` code and an Arabic slug, and is born with
     * `available_until` = the event's end, so it leaves the store on its own.
     *
     * Deliberately a SHORT form: name, description, price, stock, one photo. Anything
     * more (sale price, options, SMACC code) is the full product editor's job, one
     * click away. Guarded by `products.create` on the route as well as the event's
     * own permission, so managing events is not a back door into the catalogue.
     */
    public function createOffer(Request $request, StoreEvent $storeEvent, ChangeLogService $changeLog)
    {
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description_ar' => ['nullable', 'string', 'max:5000'],
            'description_en' => ['nullable', 'string', 'max:5000'],
            // gt:0, not min:0 — a zero price fails the publish guard and the offer
            // would be created hidden, which reads as the form not working.
            'price' => ['required', 'numeric', 'gt:0'],
            'stock' => ['required', 'integer', 'min:0'],
            'image' => $this->imageRules(required: true),
        ]);

        DB::transaction(function () use ($request, $storeEvent, $data, $changeLog) {
            $product = Product::create([
                'category_id' => StoreEvent::offersCategory()->id,
                'name_ar' => $data['name_ar'],
                'name_en' => $data['name_en'] ?? null,
                'description_ar' => $data['description_ar'] ?? null,
                'description_en' => $data['description_en'] ?? null,
                'price' => $data['price'],
                'stock' => $data['stock'],
                'slug' => ProductCodes::uniqueSlug($data['name_ar']),
                'sku' => ProductCodes::nextSku(),
                'is_active' => true,
                'available_until' => $storeEvent->ends_at,
            ]);

            $product->images()->create([
                'path' => Media::storeImage($request->file('image'), "products/{$product->id}"),
                'sort_order' => 1,
                'is_primary' => true,
            ]);

            $storeEvent->products()->syncWithoutDetaching([
                $product->id => ['sort_order' => (int) $storeEvent->products()->max('event_product.sort_order') + 1],
            ]);

            $changeLog->logCreated($product, $product->name_ar);
        });

        return back()->with('success', __('messages.admin.store_event_offer_created'));
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
        $request->validate(['banner' => $this->imageRules(required: true, maxKb: 4096)]);

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

    /**
     * Add a homepage hero banner to the event.
     *
     * Multipart POST (two files). The phone art is optional: without it the hero
     * shows the desktop art on phones too — see hero.tsx for why that choice is
     * made for the whole set rather than per banner.
     */
    public function storeBanner(Request $request, StoreEvent $storeEvent)
    {
        $data = $request->validate([
            'image' => $this->imageRules(required: true),
            'image_mobile' => $this->imageRules(required: false),
            'product_id' => ['nullable', 'integer', $this->offerOf($storeEvent)],
            'alt_ar' => ['nullable', 'string', 'max:255'],
            'alt_en' => ['nullable', 'string', 'max:255'],
            ...$this->windowRules($request),
        ]);

        $storeEvent->heroBanners()->create([
            'image' => Media::storeImage($request->file('image'), "events/{$storeEvent->id}/hero"),
            'image_mobile' => $request->hasFile('image_mobile')
                ? Media::storeImage($request->file('image_mobile'), "events/{$storeEvent->id}/hero")
                : null,
            'product_id' => $data['product_id'] ?? null,
            'alt_ar' => $data['alt_ar'] ?? null,
            'alt_en' => $data['alt_en'] ?? null,
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'sort_order' => (int) $storeEvent->heroBanners()->max('sort_order') + 1,
        ]);

        return back()->with('success', __('messages.admin.store_event_banner_added'));
    }

    /** Link, alt text, own window and on/off. Every field optional so the toggle can send just one. */
    public function updateBanner(Request $request, StoreEvent $storeEvent, EventHeroBanner $banner)
    {
        $this->assertBanner($storeEvent, $banner);

        $data = $request->validate([
            'product_id' => ['sometimes', 'nullable', 'integer', $this->offerOf($storeEvent)],
            'alt_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'alt_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            ...$this->windowRules($request, sometimes: true),
        ]);

        $banner->update($data);

        return back()->with('success', __('messages.admin.store_event_banner_saved'));
    }

    public function destroyBanner(StoreEvent $storeEvent, EventHeroBanner $banner)
    {
        $this->assertBanner($storeEvent, $banner);

        Media::delete($banner->image);
        Media::delete($banner->image_mobile);
        $banner->delete();

        return back()->with('success', __('messages.admin.store_event_banner_removed'));
    }

    public function reorderBanners(Request $request, StoreEvent $storeEvent)
    {
        $data = $request->validate([
            'banner_ids' => ['required', 'array'],
            'banner_ids.*' => ['integer'],
        ]);

        // Same shape as reorderOffers: only this event's banners, one transaction.
        $own = $storeEvent->heroBanners()->pluck('id')->all();

        DB::transaction(function () use ($data, $own) {
            foreach (array_values(array_intersect($data['banner_ids'], $own)) as $i => $id) {
                EventHeroBanner::whereKey($id)->update(['sort_order' => $i]);
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

    /** Same protection for banners: `/store-events/1/banners/9` must be event 1's banner. */
    private function assertBanner(StoreEvent $storeEvent, EventHeroBanner $banner): void
    {
        abort_unless($banner->store_event_id === $storeEvent->id, 404);
    }

    /** A banner may only link to an offer of its OWN event. */
    private function offerOf(StoreEvent $storeEvent): Exists
    {
        return Rule::exists('event_product', 'product_id')->where('store_event_id', $storeEvent->id);
    }

    /**
     * Extension AND sniffed MIME, like every upload through Media.
     *
     * @return list<string>
     */
    private function imageRules(bool $required, int $maxKb = 5120): array
    {
        return [$required ? 'required' : 'nullable', 'file', 'image',
            'mimes:'.implode(',', Media::IMAGE_EXTENSIONS),
            'mimetypes:'.implode(',', Media::IMAGE_MIMES),
            "max:{$maxKb}"];
    }

    /**
     * A banner's own optional window.
     *
     * ⚠️ `after_or_equal:starts_at` is only attached when a start was actually
     * sent: against an empty start, Laravel treats the literal "starts_at" as the
     * date to compare to, and every end date then fails.
     *
     * @return array<string, list<mixed>>
     */
    private function windowRules(Request $request, bool $sometimes = false): array
    {
        $prefix = $sometimes ? ['sometimes'] : [];

        return [
            'starts_at' => [...$prefix, 'nullable', 'date'],
            'ends_at' => [...$prefix, 'nullable', 'date', ...($request->filled('starts_at') ? ['after_or_equal:starts_at'] : [])],
        ];
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

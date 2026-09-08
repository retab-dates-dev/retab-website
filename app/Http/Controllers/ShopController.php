<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\ClientReview;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use App\Models\ReviewHelpfulVote;
use App\Models\StoreEvent;
use App\Models\Wishlist;
use App\Services\ReviewRewardService;
use App\Services\ReviewService;
use App\Support\Media;
use App\Support\ProductCards;
use App\Support\SearchText;
use App\Support\StoreEventCards;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public storefront — catalogue listing + product detail. AR-first; read-only
 * for now (cart/checkout UI comes next).
 */
class ShopController
{
    /** Curated homepage (`/`). Full product browsing lives at /shop (catalogue). */
    public function index(): Response
    {
        return Inertia::render('shop/index', [
            'bestSellers' => $this->bestSellers(),
            // Named, time-boxed campaigns — each renders its own titled strip above
            // Best Sellers. Two overlapping events therefore render two sections in
            // order, which needs no "which one wins" rule and handles the ordinary
            // single-event case for free.
            'storeEvents' => $this->storeEvents(),
            // Active discounted products for the homepage "offers" strip (empty →
            // the section renders nothing). Featured first, then newest.
            //
            // `notInRunningEvent()` is what keeps an event product out of this
            // strip — it is already shown above under the event's own name, and
            // showing it twice on one page is exactly what the separate sections
            // are meant to avoid. See the scope for its other two call sites.
            'offers' => Product::where('is_active', true)
                ->onSale()
                ->notInRunningEvent()
                ->with(['category:id,name_ar,name_en,slug', 'images', 'activeOptions'])
                ->orderByDesc('is_featured')
                ->latest()
                ->limit(10)
                ->get()
                ->map(fn (Product $p) => $this->card($p))
                ->all(),
            'newArrivals' => Product::where('is_active', true)
                ->with(['category:id,name_ar,name_en,slug', 'images', 'activeOptions'])
                ->latest()
                ->limit(10)
                ->get()
                ->map(fn (Product $p) => $this->card($p))
                ->all(),
            'featuredCategories' => Category::where('is_active', true)
                ->whereNotNull('image')
                ->orderBy('sort_order')
                ->get(['id', 'name_ar', 'name_en', 'slug', 'image'])
                ->all(),
            // Random handful of the active pool → rotates on each refresh.
            'reviews' => ClientReview::where('is_active', true)
                ->inRandomOrder()
                ->limit(4)
                ->get(['id', 'author_name', 'body', 'rating'])
                ->all(),
        ]);
    }

    /**
     * Full catalogue (`/shop`) — active products with optional category filter,
     * text search, on-sale ("Offers") filter, and sorting, paginated 12 per page.
     * Filters compose and are preserved across pagination (withQueryString).
     */
    public function catalogue(Request $request): Response
    {
        $activeCategory = $request->query('category');
        $search = trim((string) $request->query('q', ''));
        $sort = in_array($request->query('sort'), ['price_asc', 'price_desc', 'name'], true)
            ? $request->query('sort')
            : 'newest';
        $onSaleOnly = $request->boolean('on_sale');

        // Resolve only the filtered category's id — cheap, and it runs on the
        // partial (filter) reloads too, unlike the full chip list below.
        $categoryId = $activeCategory
            ? Category::where('is_active', true)->where('slug', $activeCategory)->value('id')
            : null;

        // Include Coming-Soon (hidden-but-surfaced) products alongside live ones;
        // they render as request-only cards. Buyability is still is_active-gated.
        $query = Product::visibleOnStore()
            ->with(['category:id,name_ar,name_en,slug', 'images', 'activeOptions']);

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        if ($search !== '') {
            // 🔑 Resolved against the SAME cached, normalised index the typeahead
            // uses, not a raw SQL LIKE. A LIKE on the stored text cannot fold أ/ا
            // or ة/ه, so «علبه» found nothing while the typeahead offered five
            // products — a shopper would see suggestions, press Enter, and land on
            // "no results". Matching here in PHP is free at this catalogue size and
            // keeps one definition of what a word means; the query stays in SQL, so
            // sorting and pagination are unaffected.
            $query->whereIn('id', self::cachedIndex()
                ->filter(fn (array $p) => SearchText::matches($p['terms'], $search))
                ->pluck('id')
                ->all());
        }

        if ($onSaleOnly) {
            // Same exclusion as the homepage strip that links here — an event
            // product belongs to its own section, not to "العروض". Applying it in
            // one place and not the other is what would make the link and its
            // destination disagree.
            $query->onSale()->notInRunningEvent();
        }

        match ($sort) {
            'price_asc' => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'name' => $query->orderBy('name_ar'),
            default => $query->orderByDesc('is_featured')->latest(),
        };

        $products = $query->paginate(12)->withQueryString()
            ->through(fn (Product $p) => $this->card($p));

        return Inertia::render('shop/catalogue', [
            // Deferred (closure): the chip list never changes while filtering, so
            // it's skipped on the partial reloads (which request only products /
            // filters / activeCategory) and sent only on the full first load.
            // Only categories that hold a visible product become chips — this keeps
            // the empty parent nav groups (التمور / الهدايا, which exist just to drive
            // the navbar dropdowns) and any empty leaf out of the filter, so a chip
            // never lands on an empty result.
            'categories' => fn () => Category::where('is_active', true)
                ->whereHas('products', fn ($q) => $q->visibleOnStore())
                ->orderBy('sort_order')
                ->get(['id', 'name_ar', 'name_en', 'slug']),
            'products' => $products,
            'activeCategory' => $activeCategory,
            'filters' => [
                'q' => $search,
                'sort' => $sort,
                'on_sale' => $onSaleOnly,
            ],
        ]);
    }

    /**
     * The full storefront search index (all visible products with thumbnails),
     * fetched ONCE by the catalogue typeahead which then filters in-memory — so
     * search costs zero DB hits / round-trips per keystroke. The catalogue is
     * small (dozens of SKUs), so the whole index is a few KB. Cached (memory read,
     * not a query) and busted whenever a product or its images change; the 1h TTL
     * is a safety net for time-based sale windows. Buyable products rank first.
     *
     * 🔑 Each entry carries a pre-built `terms` haystack (name in both languages,
     * SKU, category, plus synonym expansions) already run through
     * `SearchText::normalize`. Normalising the catalogue once per cache period,
     * server-side, means the client only ever has to normalise the QUERY — and it
     * is the same string the `?q=` results page matches against, so the typeahead
     * and the page it links to cannot disagree about what a word means.
     */
    public function searchIndex()
    {
        return response()->json(['products' => self::cachedIndex()]);
    }

    /**
     * The cached search index, shared by the JSON endpoint and the catalogue's own
     * `?q=` filter.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function cachedIndex(): Collection
    {
        return Cache::remember(Product::SEARCH_INDEX_CACHE, now()->addHour(), function () {
            return Product::visibleOnStore()
                ->with(['images', 'category:id,name_ar,name_en', 'activeOptions'])
                ->orderByDesc('is_active')
                ->get()
                ->map(fn (Product $p) => [
                    'id' => $p->id,
                    'slug' => $p->slug,
                    'name_ar' => $p->name_ar,
                    'name_en' => $p->name_en,
                    'image' => Media::url($p->primaryImage()?->path, 'thumb'),
                    'price' => (float) $p->price,
                    'effective_price' => $p->effectivePrice(),
                    'on_sale' => $p->isOnSale(),
                    'coming_soon' => $p->isComingSoon(),
                    'terms' => SearchText::terms([
                        $p->name_ar,
                        $p->name_en,
                        $p->sku,
                        $p->category?->name_ar,
                        $p->category?->name_en,
                    ]),
                ])
                ->values();
        });
    }

    /**
     * Top products for the homepage "best sellers" strip: ranked by units sold in
     * orders that reached a fulfilled state, then featured, then newest — so the
     * strip shows a sensible line-up even before any real sales exist.
     *
     * @return array<int, array<string, mixed>>
     */
    private function bestSellers(): array
    {
        return ProductCards::bestSellers(10);
    }

    public function show(Request $request, Product $product, ReviewService $reviewService, ReviewRewardService $reviewReward): Response
    {
        // Coming-Soon products are viewable (request-only); everything else hidden 404s.
        abort_unless($product->is_active || $product->is_coming_soon, 404);

        $product->load('category:id,name_ar,name_en,slug', 'images', 'activeOptions');
        $user = $request->user();

        // Right-sized WebP per context, all aligned by index (the gallery swaps
        // between them by index): detail for the main image, card for the small
        // thumbnail strip (a browser downscale of the 1400px detail into a ~100px
        // thumb aliases badly on detailed packaging), the full original for zoom.
        $sortedImages = $product->images->sortBy('sort_order')
            ->filter(fn ($img) => filled($img->path))
            ->values();
        $images = $sortedImages->map(fn ($img) => Media::url($img->path, 'detail'))->values();
        $imagesThumb = $sortedImages->map(fn ($img) => Media::url($img->path, 'card'))->values();
        $imagesFull = $sortedImages->map(fn ($img) => Media::url($img->path))->values();

        $reviews = Review::where('product_id', $product->id)
            ->where('is_approved', true)
            ->with('user:id,name')
            ->latest()
            ->get();

        // Which of these reviews the current user has marked helpful.
        $votedIds = $user
            ? ReviewHelpfulVote::where('user_id', $user->id)->whereIn('review_id', $reviews->pluck('id'))->pluck('review_id')->all()
            : [];

        // Verified-purchase eligibility (drives the review form + the reward nudge).
        $canReview = $user ? (bool) $reviewService->eligibleOrderId($user, $product->id) : false;

        // Units sold across fulfilled orders — social proof ("purchased N times").
        $purchaseCount = (int) OrderItem::where('product_id', $product->id)
            ->whereHas('order', fn ($q) => $q->whereIn('status', [
                OrderStatus::Confirmed->value,
                OrderStatus::Shipped->value,
                OrderStatus::Delivered->value,
            ]))
            ->sum('quantity');

        return Inertia::render('shop/product', [
            'product' => [
                'id' => $product->id,
                'name_ar' => $product->name_ar,
                'name_en' => $product->name_en,
                'slug' => $product->slug,
                'sku' => $product->sku,
                'description_ar' => $product->description_ar,
                'description_en' => $product->description_en,
                'price' => (float) $product->price,
                'sale_price' => $product->sale_price !== null ? (float) $product->sale_price : null,
                'effective_price' => $product->effectivePrice(),
                'on_sale' => $product->isOnSale(),
                // Whether the sale reaches the size options (opt-in per product).
                'sale_applies_to_options' => (bool) $product->sale_applies_to_options,
                'in_stock' => $product->stock > 0,
                'coming_soon' => $product->isComingSoon(),
                // Sellable size/packaging options, cheapest first. Empty for a
                // plain single-price product (the page renders its price as before).
                // What the product's own price is for, so the picker can name the
                // default choice by its size instead of a generic "Original".
                'base_weight_grams' => $product->base_weight_grams,
                'options' => $product->activeOptions->map(fn ($o) => [
                    'id' => $o->id,
                    'label_ar' => $o->label_ar,
                    'label_en' => $o->label_en,
                    'amount' => $o->amount,
                    'price' => (float) $o->price,
                    // How many packs are inside a box, shown to the shopper.
                    //
                    // 🔑 Deliberately NOT a second column: this IS `stock_units`,
                    // the count a box sale deducts from the shared pool, so the
                    // number the customer reads and the number the shop deducts
                    // cannot drift apart. A separate "pieces per box" field would
                    // be two records of one fact.
                    //
                    // Null means "do not show", which is what makes it optional:
                    // 1 is the default for a new box and means the count has not
                    // been entered yet, and "contains 1 pack" says nothing worth
                    // reading. Only a box carries one — a weight option's
                    // stock_units is an inventory figure, not a pack count.
                    'pieces' => $o->is_box && $o->stock_units > 1 ? (int) $o->stock_units : null,
                ])->values(),
                'purchase_count' => $purchaseCount,
                'category' => $product->category?->only('name_ar', 'name_en', 'slug'),
                'images' => $images,
                'images_thumb' => $imagesThumb,
                'images_full' => $imagesFull,
                'url' => route('shop.product', $product->slug), // absolute, for JSON-LD/OG
            ],
            'reviews' => [
                'summary' => [
                    'count' => $reviews->count(),
                    'average' => round((float) $reviews->avg('rating'), 1),
                ],
                'items' => $reviews->map(fn (Review $r) => [
                    'id' => $r->id,
                    'rating' => $r->rating,
                    'title' => $r->title,
                    'body' => $r->body,
                    'author' => $r->user?->name ?? __('messages.review.anonymous'),
                    'helpful_count' => $r->helpful_count,
                    'voted' => in_array($r->id, $votedIds, true),
                    'is_mine' => $user && $r->user_id === $user->id,
                    'date' => $r->created_at?->toDateString(),
                ])->values(),
                'can_review' => $canReview,
            ],
            // One-time "review → discount" offer (drives the storefront nudge).
            // ⚠️ camelCase to match the page's destructured prop name (and the rest
            // of the app's multi-word props) — a mismatch here is invisible to
            // tsc/PHPUnit and white-screens the page on hydration. See ShopProduct.
            'reviewReward' => [
                'available' => $canReview && $reviewReward->availableFor($user),
                'percent' => $reviewReward->percent(),
            ],
            'wishlisted' => $user
                ? Wishlist::where('user_id', $user->id)->where('product_id', $product->id)->exists()
                : false,
            'authed' => (bool) $user,
            // ⚠️ The instalment COUNT is shipped rather than hardcoded in the page.
            // It previously advertised "split into 4" while checkout requested
            // `instalments` (3 by default), so a shopper was quoted one plan and
            // shown another on Tamara's own page. Both now read one config value,
            // which is the same one `TamaraService::buildCheckoutPayload()` sends.
            'tamaraInstalments' => (int) config('services.tamara.instalments', 3),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function card(Product $product): array
    {
        return ProductCards::card($product);
    }

    /**
     * Running store events, each with its live offers.
     *
     * ⚠️ Events whose offers all turned out to be hidden are dropped rather than
     * rendered empty: a titled section with nothing under it reads as a broken
     * page, and it is a realistic state — a product can be deactivated (or fail
     * the publish guard) while it is still attached to a live event.
     *
     * @return array<int, array<string, mixed>>
     */
    private function storeEvents(): array
    {
        return StoreEvent::running()
            ->with(['products' => fn ($q) => $q->where('is_active', true)
                ->with(['images', 'activeOptions'])])
            ->orderBy('sort_order')
            ->orderBy('starts_at')
            ->get()
            ->map(fn (StoreEvent $e) => StoreEventCards::payload($e))
            ->filter(fn (array $e) => $e['offers'] !== [])
            ->values()
            ->all();
    }
}

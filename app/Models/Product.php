<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * @mixin IdeHelperProduct
 */
class Product extends Model
{
    use SoftDeletes;

    /** Cache key for the storefront typeahead index (see ShopController::searchIndex). */
    public const SEARCH_INDEX_CACHE = 'shop.search_index';

    /**
     * What a product must have before the storefront may show it.
     *
     * 🔑 An ENGLISH NAME is deliberately not here (decided 2026-09-01). The
     * storefront is Arabic-first and `useLocalized()` falls back to the Arabic
     * name, so an English visitor sees a correctly-named product either way —
     * blocking on it would be stricter than this app's own bilingual contract
     * ("AR required, EN nullable, falls back to AR"). It is still surfaced: the
     * products list badges it and it counts toward the dashboard's
     * needs-completing tile, so it gets filled in without holding a sale up.
     *
     * A description is excluded for the same reason — worth prompting for, but a
     * missing one does not mislead a shopper the way a nameless, priceless or
     * pictureless listing does.
     */
    public const PUBLISH_REQUIREMENTS = ['price', 'name_ar', 'image'];

    /** Surfaced to staff and counted on the dashboard, but never blocking. */
    public const PUBLISH_ADVISORIES = ['name_en'];

    protected static function booted(): void
    {
        // Any product change invalidates the cached search index (ProductImage
        // busts it too, since a primary-image change alters an index thumbnail).
        static::saved(fn () => Cache::forget(self::SEARCH_INDEX_CACHE));
        static::deleted(fn () => Cache::forget(self::SEARCH_INDEX_CACHE));

        // 🔴 THE SAFETY GUARD. An incomplete product can never be live, whatever
        // route the write came in on.
        //
        // Enforced on the MODEL rather than in the controller on purpose: the
        // admin form is only one of several ways `is_active` gets written —
        // there is also the one-click list toggle, a change-log revert (which
        // writes a whole old row back and would otherwise re-publish a product
        // that has since lost its image), the catalogue importers, and tinker.
        // A controller-side check would cover the first and silently miss the
        // rest.
        //
        // It DOWNGRADES rather than throwing: the point is that staff can always
        // save their work in progress and the storefront simply does not show it
        // until it is finished. Refusing the save instead is what made the five
        // image-less products uneditable.
        static::saving(function (self $product): void {
            if ($product->is_active && $product->missingForPublish() !== []) {
                $product->is_active = false;
            }

            // A time-boxed product (a store-event offer) can never be live past
            // its `available_until`. Same downgrade-not-refuse shape as the guard
            // above, and on the model for the same reason: a change-log revert or
            // a list toggle must not quietly re-publish a campaign that is over.
            // The passage of time itself is covered by `catalog:hide-expired`,
            // which runs every minute — nothing is saved when a clock ticks.
            if ($product->is_active && $product->isPastAvailability()) {
                $product->is_active = false;
            }
        });
    }

    /** True once a time-boxed product's `available_until` has arrived. */
    public function isPastAvailability(): bool
    {
        return $this->available_until !== null && ! $this->available_until->isFuture();
    }

    /**
     * Which of PUBLISH_REQUIREMENTS this product still fails. Empty ⇒ publishable.
     *
     * ⚠️ The image check hits the database unless `images` is already loaded, so
     * callers listing many products should eager-load or use withCount to avoid
     * a query per row. The saving hook only reaches it for products that are
     * being made active, so an ordinary hidden-product save costs nothing.
     *
     * ⚠️ A product that does not exist yet cannot have images — `store()` creates
     * the row first and attaches images after — so this reports `image` as
     * missing on a brand-new record. That is why the create path re-applies the
     * requested visibility once the images are on (see Admin\ProductController).
     *
     * @return list<string>
     */
    public function missingForPublish(): array
    {
        $missing = [];

        if ((float) $this->price <= 0) {
            $missing[] = 'price';
        }
        if (trim((string) $this->name_ar) === '') {
            $missing[] = 'name_ar';
        }
        if (! $this->hasAnyImage()) {
            $missing[] = 'image';
        }

        return $missing;
    }

    /**
     * Things worth fixing that do NOT stop the product being sold — currently
     * just a missing English name. Kept separate from missingForPublish() so the
     * two can never be confused: one is a gate, the other is a nudge.
     *
     * @return list<string>
     */
    public function publishAdvisories(): array
    {
        return trim((string) $this->name_en) === '' ? ['name_en'] : [];
    }

    public function isPublishable(): bool
    {
        return $this->missingForPublish() === [];
    }

    /**
     * Products that fail at least one publish requirement — the SQL twin of
     * missingForPublish().
     *
     * 🔴 The two MUST agree. A product this scope omits but the guard rejects
     * would be invisible on the "needs attention" list while refusing to go
     * live, which reads as the toggle being broken. Pinned by a test that walks
     * every product and asserts the scope's result equals the set whose
     * missingForPublish() is non-empty — change one, change both.
     *
     * TRIM/COALESCE rather than `= ''` because a name of only spaces is just as
     * unusable as an empty one, and both MySQL and SQLite support them.
     */
    public function scopeIncompleteForPublish(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            // Mirrors PUBLISH_REQUIREMENTS exactly — blocking only. A missing
            // English name is an advisory and deliberately absent, so the
            // dashboard tile and this filter count the same set: products that
            // genuinely cannot be shown.
            $q->where('price', '<=', 0)
                ->orWhereRaw("TRIM(COALESCE(name_ar, '')) = ''")
                ->orWhereDoesntHave('images');
        });
    }

    /**
     * Re-check a saved product and hide it if it is no longer publishable.
     *
     * Needed because images live on their own endpoints: deleting the last one
     * leaves the product row untouched, so nothing would otherwise re-evaluate
     * it. Returns true when it actually changed something, so the caller can
     * tell the admin what happened instead of silently pulling a live product.
     */
    public function syncPublishability(): bool
    {
        if (! $this->is_active || $this->isPublishable()) {
            return false;
        }

        // The saving hook does the actual downgrade; this only triggers a save.
        $this->is_active = true;
        $this->save();

        return true;
    }

    /**
     * Uses the loaded relation when there is one, so lists don't re-query.
     *
     * 🔑 A record that does not exist yet is treated as SATISFYING this rule.
     * Images are a separate table, so they can only ever be attached after the
     * insert — judging a brand-new product on them would make
     * `Product::create([... 'is_active' => true])` impossible to satisfy in one
     * statement, for every seeder, importer and test in the codebase, and the
     * only way back would be a second save that looks like a mistake.
     *
     * The rule still bites where it matters: the admin create form requires
     * images before it will accept the request, every later save re-checks, and
     * deleting the last image re-checks explicitly. What is deliberately NOT
     * covered is a script that inserts an active product and never attaches an
     * image — trusted code, and the dashboard tile lists it if it happens.
     */
    private function hasAnyImage(): bool
    {
        if (! $this->exists) {
            return true;
        }

        if ($this->relationLoaded('images')) {
            return $this->images->isNotEmpty();
        }

        if (isset($this->attributes['images_count'])) {
            return (int) $this->attributes['images_count'] > 0;
        }

        return $this->images()->exists();
    }

    protected $fillable = [
        'category_id',
        'name_ar',
        'name_en',
        'slug',
        'description_ar',
        'description_en',
        'short_description_ar',
        'short_description_en',
        'price',
        'base_weight_grams',
        'sale_price',
        'sale_starts_at',
        'sale_ends_at',
        'sale_applies_to_options',
        'sku',
        'smacc_sku',
        'barcode',
        'stock',
        'low_stock_threshold',
        'is_active',
        'is_featured',
        'is_coming_soon',
        'available_until',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'base_weight_grams' => 'integer',
        'sale_price' => 'decimal:2',
        'sale_starts_at' => 'datetime',
        'sale_ends_at' => 'datetime',
        'sale_applies_to_options' => 'boolean',
        'stock' => 'integer',
        'low_stock_threshold' => 'integer',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'is_coming_soon' => 'boolean',
        'available_until' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    /**
     * The primary image (or the first by sort order). Expects `images` loaded.
     */
    public function primaryImage(): ?ProductImage
    {
        return $this->images->firstWhere('is_primary', true)
            ?? $this->images->sortBy('sort_order')->first();
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function wishlists(): HasMany
    {
        return $this->hasMany(Wishlist::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(ProductRequest::class);
    }

    /**
     * Sellable options (sizes / packaging), cheapest first. A product with no
     * rows here is a plain single-price product and behaves exactly as before.
     */
    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class)->orderBy('sort_order')->orderBy('amount');
    }

    /**
     * The named store campaigns this product has been attached to (past, running
     * and scheduled). Filter with `->running()` for the live one.
     */
    public function storeEvents(): BelongsToMany
    {
        return $this->belongsToMany(StoreEvent::class, 'event_product')
            ->withPivot(['banner_image', 'badge_ar', 'badge_en', 'sort_order'])
            ->withTimestamps();
    }

    /**
     * Active options only, cheapest first — what the storefront may offer.
     */
    public function activeOptions(): HasMany
    {
        return $this->options()->where('is_active', true);
    }

    /**
     * The cheapest active option's price, or null when the product has no
     * options. Uses an already-loaded relation when present so lists (search
     * index, cards) that eager-load `activeOptions` cost no extra query.
     */
    public function minOptionPrice(): ?float
    {
        foreach (['activeOptions', 'options'] as $relation) {
            if ($this->relationLoaded($relation)) {
                $loaded = $this->getRelation($relation)->where('is_active', true);

                return $loaded->isEmpty() ? null : (float) $loaded->min('price');
            }
        }

        $min = $this->activeOptions()->min('price');

        return $min === null ? null : (float) $min;
    }

    /**
     * Whether this product sells through a list of options rather than a single
     * price.
     */
    public function hasOptions(): bool
    {
        return $this->minOptionPrice() !== null;
    }

    /**
     * Everything a customer may see on the storefront: live (buyable) products PLUS
     * hidden ones flagged Coming Soon (visible, request-only). Buyability is still
     * gated purely by is_active everywhere else (cart, checkout), so this scope only
     * widens what LISTS, never what can be purchased.
     */
    public function scopeVisibleOnStore(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('is_active', true)->orWhere('is_coming_soon', true));
    }

    /**
     * A hidden product being shown on the store in request-only mode. When it's
     * live (is_active) it's a normal buyable product, never "coming soon".
     */
    public function isComingSoon(): bool
    {
        return ! $this->is_active && $this->is_coming_soon;
    }

    /**
     * Products currently on sale — the SQL mirror of isOnSale(): a sale_price set
     * below the regular price, within its (optional) date window. Used for the
     * catalogue "Offers" filter so on-sale filtering happens in the query, not
     * post-hydration.
     */
    public function scopeOnSale(Builder $query): Builder
    {
        $now = Carbon::now();

        return $query->whereNotNull('sale_price')
            ->whereColumn('sale_price', '<', 'price')
            ->where(fn (Builder $q) => $q->whereNull('sale_starts_at')->orWhere('sale_starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('sale_ends_at')->orWhere('sale_ends_at', '>=', $now));
    }

    /**
     * Products NOT attached to a currently-running store event.
     *
     * 🔴 This is the rule that keeps the two offer surfaces from mixing, and it is
     * the whole reason a named event can sit beside the ordinary discounts strip.
     * A product in a running event is shown in that event's own section, headed by
     * the event name; without this scope a discounted event product would ALSO
     * appear in the العروض strip lower down the same page, i.e. twice.
     *
     * ⚠️ It has THREE call sites and they must agree, because two of them are the
     * link and the page it points at:
     *   1. ShopController::index      — the العروض homepage strip
     *   2. ShopController::catalogue  — /shop?on_sale=1, where that strip links to
     *   3. HandleInertiaRequests      — `hasOffers`, which drives the Offers nav
     *      item, the mobile drawer entry and the cart's empty-state link
     * Miss the third and the nav offers a link to a page with nothing on it.
     *
     * Deliberately scoped to the on-sale surfaces only: an event product is still
     * an ordinary product everywhere else, so it keeps its category and still
     * appears in normal browsing and search.
     */
    public function scopeNotInRunningEvent(Builder $query): Builder
    {
        return $query->whereDoesntHave('storeEvents', fn (Builder $q) => $q->running());
    }

    /**
     * True when a sale price is set and actually below the regular price.
     */
    public function isOnSale(): bool
    {
        if ($this->sale_price === null || $this->sale_price >= $this->price) {
            return false;
        }

        // A null window bound means "no bound": active immediately / indefinitely.
        if ($this->sale_starts_at && $this->sale_starts_at->isFuture()) {
            return false;
        }
        if ($this->sale_ends_at && $this->sale_ends_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Sale lifecycle label for the admin (a sale_price is set, but the window may
     * be pending or over): scheduled / expired / active. Assumes sale_price set.
     */
    public function saleStatus(): string
    {
        if ($this->sale_starts_at && $this->sale_starts_at->isFuture()) {
            return 'scheduled';
        }
        if ($this->sale_ends_at && $this->sale_ends_at->isPast()) {
            return 'expired';
        }

        return 'active';
    }

    /**
     * The product's sale discount as a multiplier (sale ÷ regular), or 1.0 when
     * not on sale.
     */
    public function saleRatio(): float
    {
        if ($this->isOnSale() && $this->price > 0) {
            return (float) $this->sale_price / (float) $this->price;
        }

        return 1.0;
    }

    /**
     * The discount multiplier that applies to SIZE OPTIONS: the sale ratio, but
     * only when the admin opted the sale into the sizes (sale_applies_to_options).
     * A discount is on the original price by default and does NOT cascade to the
     * derived size prices unless that flag is set.
     */
    public function optionSaleRatio(): float
    {
        return $this->sale_applies_to_options ? $this->saleRatio() : 1.0;
    }

    /**
     * The price of the ORIGINAL product — the always-present default choice, even
     * once sizes are added. The discount is on the original price, so a sale
     * always applies here (unlike the sizes, which opt in).
     */
    public function originalPrice(): float
    {
        return (float) ($this->isOnSale() ? $this->sale_price : $this->price);
    }

    /**
     * What the customer actually pays for a chosen option: its stored (regular)
     * price, discounted only if the sale was opted into the sizes. Used by the
     * cart and checkout so what is charged matches what is shown.
     */
    public function optionEffectivePrice(ProductOption $option): float
    {
        return round((float) $option->price * $this->optionSaleRatio(), 2);
    }

    /**
     * The amount charged for a purchase: a chosen size's effective price, or the
     * ORIGINAL price when no size is chosen (the default choice). One method so
     * the cart, checkout and product page can't disagree.
     */
    public function priceForOption(?ProductOption $option): float
    {
        return $option ? $this->optionEffectivePrice($option) : $this->originalPrice();
    }

    /**
     * The price used for LISTINGS ("from X"): the cheapest thing a customer can
     * actually buy — the original, or a size if one is cheaper. For a plain
     * product this is just the original (sale price when on sale, else regular).
     */
    public function effectivePrice(): float
    {
        $min = $this->minOptionPrice();
        if ($min === null) {
            return $this->originalPrice();
        }

        return min($this->originalPrice(), round($min * $this->optionSaleRatio(), 2));
    }

    /**
     * Stock at or below the product threshold (falls back to 0 when unset).
     */
    public function isLowStock(): bool
    {
        return $this->stock <= ($this->low_stock_threshold ?? 0);
    }
}

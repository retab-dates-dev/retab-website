<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A named, time-boxed store campaign (اليوم الوطني السعودي, عروض رمضان) shown as
 * its own homepage strip above Best Sellers, headed by the event's own name.
 *
 * ⚠️ Named `StoreEvent`, not `Event`, on purpose: an `Event` model collides with
 * `Illuminate\Support\Facades\Event` in every file that dispatches one, and the
 * resulting alias juggling is a permanent tax for a one-word saving.
 *
 * @mixin IdeHelperStoreEvent
 */
class StoreEvent extends Model
{
    /** Brand teal — what an event with no accent of its own renders as. */
    public const DEFAULT_ACCENT = '#1b4e53';

    /**
     * Accent presets offered in the admin. A free colour picker is a licence to
     * pick a colour that fights the rest of the page, so the admin picks from
     * these; the stored column is still a plain hex, so a one-off is possible
     * without a migration.
     */
    public const ACCENT_PRESETS = [
        'brand' => self::DEFAULT_ACCENT,
        'national_day' => '#006c35', // Saudi flag green
        'ramadan' => '#4a3b73',
        'eid' => '#0f766e',
        'gold' => '#af9056',
    ];

    protected $fillable = [
        'name_ar',
        'name_en',
        'subtitle_ar',
        'subtitle_en',
        'starts_at',
        'ends_at',
        'accent_color',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * The offers in this event, in the order the admin arranged them.
     *
     * Ordered on the PIVOT's sort_order, so reordering an event never touches the
     * products themselves and the same product can sit third in one event and
     * first in the next.
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'event_product')
            ->withPivot(['banner_image', 'badge_ar', 'badge_en', 'sort_order'])
            ->withTimestamps()
            ->orderBy('event_product.sort_order')
            ->orderBy('event_product.id');
    }

    /** The event's homepage hero banners, in the admin's arranged order. */
    public function heroBanners(): HasMany
    {
        return $this->hasMany(EventHeroBanner::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * Events live on the storefront right now: switched on AND inside their window.
     *
     * The SQL mirror of isRunning(). Both bounds are NOT NULL by schema, so unlike
     * `Product::scopeOnSale()` this needs no "null means unbounded" branches.
     */
    public function scopeRunning(Builder $query): Builder
    {
        $now = Carbon::now();

        return $query->where('is_active', true)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>=', $now);
    }

    public function isRunning(): bool
    {
        return $this->is_active
            && ! $this->starts_at->isFuture()
            && ! $this->ends_at->isPast();
    }

    /**
     * Lifecycle label for the admin list: paused / scheduled / ended / active.
     * Mirrors `Coupon::status()` and `Product::saleStatus()` so the three read the
     * same way in the panel.
     */
    public function state(): string
    {
        if (! $this->is_active) {
            return 'paused';
        }
        if ($this->starts_at->isFuture()) {
            return 'scheduled';
        }
        if ($this->ends_at->isPast()) {
            return 'ended';
        }

        return 'active';
    }

    /**
     * Where campaign-only products live: the Special Offers category, seeded by
     * the store-events migration. firstOrCreate as a belt-and-braces for a
     * database where that row was deleted by hand — an offer created into a
     * missing category would fail its insert instead.
     */
    public static function offersCategory(): Category
    {
        return Category::firstOrCreate(
            ['slug' => 'special-offers'],
            ['name_ar' => 'العروض الخاصة', 'name_en' => 'Special Offers', 'sort_order' => 90, 'is_active' => true],
        );
    }

    /** The event's accent, falling back to brand teal when it has none. */
    public function accent(): string
    {
        return $this->accent_color ?: self::DEFAULT_ACCENT;
    }
}

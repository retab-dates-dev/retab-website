<?php

namespace App\Support;

use App\Models\Product;
use App\Models\StoreEvent;

/**
 * The storefront payload for a named store event and its offers, consumed by
 * `components/store/store-event.tsx`.
 *
 * Kept out of ShopController for the same reason as ProductCards: the shape is
 * shared by the homepage and (later) any event landing page, and one definition
 * beats two that drift.
 */
class StoreEventCards
{
    /**
     * One event plus its offers, in the admin's arranged order.
     *
     * Ships BOTH locales for every string because the client picks via
     * `useLocalized()`, which is what keeps the AR⇄EN toggle instant.
     *
     * ⚠️ Reads `$event->products` as already loaded and already filtered — the
     * caller constrains the eager load to live products, so an event holding a
     * hidden product simply has fewer offers rather than a card that 404s.
     *
     * @return array<string, mixed>
     */
    public static function payload(StoreEvent $event): array
    {
        return [
            'id' => $event->id,
            'name_ar' => $event->name_ar,
            'name_en' => $event->name_en,
            'subtitle_ar' => $event->subtitle_ar,
            'subtitle_en' => $event->subtitle_en,
            // Resolved here rather than shipping the nullable column, so the
            // component never has to know what the fallback colour is.
            'accent' => $event->accent(),
            // ISO, because the "ends in N days" line is rendered client-side in the
            // viewer's own timezone — the server's clock is the container's.
            'ends_at' => $event->ends_at->toIso8601String(),
            'offers' => $event->products->map(fn (Product $p) => self::offer($p))->all(),
        ];
    }

    /**
     * One offer card. Carries the product's own price fields plus the two things
     * that belong to the offer rather than the product: its badge and its artwork.
     *
     * @return array<string, mixed>
     */
    private static function offer(Product $product): array
    {
        $pivot = $product->pivot;
        $onSale = $product->isOnSale();

        return [
            'id' => $product->id,
            'name_ar' => $product->name_ar,
            'name_en' => $product->name_en,
            'slug' => $product->slug,
            'price' => (float) $product->price,
            'sale_price' => $product->sale_price !== null ? (float) $product->sale_price : null,
            'effective_price' => $product->effectivePrice(),
            'has_options' => $product->hasOptions(),
            'on_sale' => $onSale,
            // Derived, so the commonest badge ("خصم ٢٠٪") needs nothing typed and
            // can never disagree with the price beside it. A badge the discount
            // cannot express ("كرتونين + الثالث مجاناً") is typed on the pivot and
            // wins over this.
            'discount_percent' => $onSale
                ? (int) round((1 - ((float) $product->sale_price / (float) $product->price)) * 100)
                : null,
            'badge_ar' => $pivot?->badge_ar,
            'badge_en' => $pivot?->badge_en,
            // The offer's own artwork when there is any, else the product's primary
            // photo cropped to 2:1 by the card. `detail` (1400px) rather than
            // `card` (500px): these render ~780px wide at two per row.
            //
            // 2:1, not the original 16:9 (changed 2026-09-13): 2:1 is the shape
            // campaign artwork is delivered in — the same as the hero banners — and
            // a 16:9 crop of a 2:1 banner cuts ~6% off each side, where designers
            // put text.
            'image' => Media::url($pivot?->banner_image ?: $product->primaryImage()?->path, 'detail'),
        ];
    }
}

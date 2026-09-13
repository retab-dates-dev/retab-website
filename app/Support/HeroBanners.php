<?php

namespace App\Support;

use App\Models\EventHeroBanner;

/**
 * The homepage hero's campaign banners, consumed by `components/store/hero.tsx`.
 *
 * Empty ⇒ the hero shows its ordinary copy slides; non-empty ⇒ it shows ONLY
 * these (the two are different shapes and must not share one carousel).
 */
class HeroBanners
{
    /** @return list<array<string, mixed>> */
    public static function live(): array
    {
        return EventHeroBanner::live()
            // Ordered like the event strips (event sort, then start), then the
            // admin's order within the event. The join is only for ORDER BY.
            ->join('store_events', 'store_events.id', '=', 'event_hero_banners.store_event_id')
            ->orderBy('store_events.sort_order')
            ->orderBy('store_events.starts_at')
            ->orderBy('event_hero_banners.sort_order')
            ->orderBy('event_hero_banners.id')
            ->select('event_hero_banners.*')
            ->with(['product:id,slug,name_ar,name_en', 'event:id,name_ar,name_en'])
            ->get()
            ->map(fn (EventHeroBanner $b) => [
                'id' => $b->id,
                'image' => Media::url($b->image, 'hero'),
                // Null when there is no phone art: the hero then keeps the desktop
                // art on phones too (see hero.tsx — one shape for the whole set).
                'image_mobile' => $b->image_mobile ? Media::url($b->image_mobile, 'detail') : null,
                'href' => $b->product ? "/products/{$b->product->slug}" : "/shop?event={$b->store_event_id}",
                'alt_ar' => $b->alt_ar ?: ($b->product?->name_ar ?: $b->event->name_ar),
                'alt_en' => $b->alt_en ?: ($b->product?->name_en ?: $b->event->name_en),
            ])
            ->values()
            ->all();
    }
}

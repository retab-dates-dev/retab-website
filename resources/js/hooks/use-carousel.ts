import { useCallback, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

/**
 * The shared scroll-snap carousel behaviour behind the homepage strips: which
 * arrows are live, whether the track overflows at all, and paging by exactly one
 * viewport of cards.
 *
 * Extracted from `product-carousel.tsx` when the store-event strip needed the same
 * thing. The RTL handling below is the reason this is shared rather than copied —
 * it is subtle, it was got wrong once already, and a second copy would drift.
 *
 * @param gap        Track gap in px. Must match the `gap-*` class on the track, or
 *                   paging lands mid-card instead of on a fresh set.
 * @param deps       Extra values that should re-measure (e.g. item count).
 * @param onMeasure  Called with the track on every measure, for callers that need
 *                   a derived layout figure (the product strip centres its arrows
 *                   on the card image, not on the taller card).
 */
export function useCarousel({
    gap,
    deps = [],
    onMeasure,
    initialScrollable = false,
}: {
    gap: number;
    deps?: unknown[];
    onMeasure?: (track: HTMLDivElement) => void;
    /**
     * What to assume before the first measure. It is what the SSR sidecar renders,
     * so pass the caller's own "more cards than fit" heuristic to keep the arrows
     * in the server HTML and avoid a frame without them on hydration.
     */
    initialScrollable?: boolean;
}) {
    const { i18n } = useTranslation();
    const trackRef = useRef<HTMLDivElement>(null);
    // Kept as PHYSICAL directions ("is there anything further left?"), because the
    // buttons are physical. Deriving them from a start/end pair silently inverts
    // them under RTL — see measure().
    const [edges, setEdges] = useState({ canLeft: false, canRight: true });
    // Whether the track actually overflows at the current breakpoint — drives both
    // the arrows (hidden when everything fits) and centring (few cards are centred
    // rather than hugging the start).
    const [scrollable, setScrollable] = useState(initialScrollable);

    const measure = useCallback(() => {
        const el = trackRef.current;
        if (!el) return;
        const max = el.scrollWidth - el.clientWidth;
        // scrollLeft runs 0..max in LTR but -max..0 in RTL (modern browsers keep 0
        // at the reading start and go negative toward the end), so the travel limits
        // have to be read per direction. Collapsing them with Math.abs() makes "at
        // the start" mean the LEFT edge in LTR and the RIGHT edge in RTL, which
        // inverted both buttons on the Arabic site: the arrow that actually paged
        // was the disabled one, and the enabled one clamped and did nothing.
        const rtl = getComputedStyle(el).direction === 'rtl';
        const pos = el.scrollLeft;
        setEdges({
            canLeft: pos > (rtl ? -max : 0) + 1,
            canRight: pos < (rtl ? 0 : max) - 1,
        });
        setScrollable(max > 1);
        onMeasure?.(el);
    }, [onMeasure]);

    useEffect(() => {
        measure();
        const el = trackRef.current;
        if (!el) return;
        el.addEventListener('scroll', measure, { passive: true });
        window.addEventListener('resize', measure);
        return () => {
            el.removeEventListener('scroll', measure);
            window.removeEventListener('resize', measure);
        };
        // `i18n.language`: the locale toggle flips document.dir WITHOUT a reload or a
        // resize, so nothing else would re-run measure() and the arrows would keep
        // the previous direction's enabled states until the next scroll.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [measure, i18n.language, ...deps]);

    const page = useCallback(
        (dir: 'left' | 'right') => {
            const el = trackRef.current;
            if (!el) return;
            // One viewport of cards. Because cards exactly fill the track, clientWidth
            // + one gap is a whole number of card steps, so the scroll lands on a
            // fresh full set regardless of how many are visible at this breakpoint.
            const step = el.clientWidth + gap;
            el.scrollBy({ left: dir === 'left' ? -step : step, behavior: 'smooth' });
        },
        [gap],
    );

    return { trackRef, edges, scrollable, page };
}

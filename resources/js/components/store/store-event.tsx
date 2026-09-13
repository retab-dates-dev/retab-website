import { useCarousel } from '@/hooks/use-carousel';
import { useLocalized } from '@/lib/localize';
import { Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export interface EventOffer {
    id: number;
    name_ar: string;
    name_en: string | null;
    slug: string;
    price: number;
    sale_price: number | null;
    effective_price: number;
    has_options: boolean;
    on_sale: boolean;
    discount_percent: number | null;
    badge_ar: string | null;
    badge_en: string | null;
    image: string | null;
}

export interface StoreEventPayload {
    id: number;
    name_ar: string;
    name_en: string | null;
    subtitle_ar: string | null;
    subtitle_en: string | null;
    accent: string;
    ends_at: string;
    offers: EventOffer[];
}

// Track gap (Tailwind gap-5 = 1.25rem = 20px). Kept in sync with the card basis
// calc() below and the page-scroll step.
const GAP = 20;

/** Rounded-triangle arrow, matching the product strips. Points right by default. */
function Arrow({ flip }: { flip?: boolean }) {
    return (
        <svg viewBox="0 0 24 25" fill="none" aria-hidden className={`h-[15px] w-[14px] sm:h-[22px] sm:w-[21px] ${flip ? '-scale-x-100' : ''}`}>
            <path
                d="M19.9167 6.95319C24.0648 9.34813 24.0648 15.3355 19.9167 17.7304L9.33334 23.8407C5.18519 26.2356 0 23.242 0 18.4521V6.23151C0 1.44165 5.18519 -1.55203 9.33333 0.842905L19.9167 6.95319Z"
                fill="currentColor"
            />
        </svg>
    );
}

/**
 * A named store campaign ("اليوم الوطني السعودي") as its own homepage strip above
 * Best Sellers. TWO offers per row, wide 16:9 cards, carousel past that.
 *
 * 🔑 Deliberately NOT the ordinary discounts strip in bigger clothing. The heading
 * is the event's own name, it carries the event's accent, and a product shown here
 * is excluded from العروض for the duration — see `Product::scopeNotInRunningEvent`.
 *
 * The accent arrives per event and drives heading, rule, badge and price through
 * one `--ev` custom property, so an event that wants no dressing simply ships
 * brand teal and nothing here changes.
 */
export default function StoreEventSection({ event }: { event: StoreEventPayload }) {
    const { t, i18n } = useTranslation();
    const localized = useLocalized();
    const currency = t('common.currency');

    // Two per row at every width, so the same card count pages on desktop and
    // phone — unlike the product strips, which step 4 / 3 / 2. These cards carry
    // overlaid copy, and at three across it stops being legible.
    const { trackRef, edges, scrollable, page } = useCarousel({
        gap: GAP,
        deps: [event.offers.length],
        initialScrollable: event.offers.length > 2,
    });

    if (event.offers.length === 0) return null;

    // Whole days left, rounded DOWN, so the number a shopper reads is never more
    // generous than the truth. Computed client-side: the server clock is the
    // container's, and "ends in 3 days" has to mean 3 days where the reader is.
    const msLeft = new Date(event.ends_at).getTime() - Date.now();
    const daysLeft = Math.max(0, Math.floor(msLeft / 86_400_000));
    const subtitle = localized(event, 'subtitle');

    const arrowBase =
        'absolute top-[38%] z-10 -translate-y-1/2 rounded-full bg-white/90 p-2 shadow-md backdrop-blur-sm transition-opacity max-sm:p-1.5';

    return (
        <section
            className="relative w-full overflow-hidden py-10 sm:py-14"
            // The tint is mixed FROM the accent rather than being a second stored
            // colour, so an event can never be given a background that fights its
            // own heading.
            style={
                {
                    '--ev': event.accent,
                    background: `linear-gradient(180deg, color-mix(in srgb, ${event.accent} 7%, white) 0%, white 100%)`,
                } as React.CSSProperties
            }
        >
            <div className="relative mx-auto max-w-[1600px] px-6 lg:px-12">
                <div className="mb-6 text-center sm:mb-8">
                    {/* The window, above the name — it is the reason the section is
                        here at all, and it is what makes the offer feel finite. */}
                    <div className="mb-2 flex items-center justify-center gap-2 text-[var(--ev)]">
                        <i className="block h-[3px] w-5 rounded-full bg-current opacity-40" />
                        <span className="font-heading text-[0.7rem] font-medium tracking-[0.16em] uppercase">
                            {daysLeft > 0 ? t('storeEvent.endsIn', { n: daysLeft }) : t('storeEvent.endsToday')}
                        </span>
                        <i className="block h-[3px] w-5 rounded-full bg-current opacity-40" />
                    </div>

                    <h2 className="font-heading text-[clamp(1.75rem,4vw,2.75rem)] font-black text-[var(--ev)]">{localized(event, 'name')}</h2>

                    {subtitle && <p className="text-brand-teal/60 mt-1.5 text-sm font-medium">{subtitle}</p>}

                    <div className="mx-auto mt-3 h-[3px] w-12 rounded-full bg-[var(--ev)]" />
                </div>

                <div className="relative">
                    {scrollable && (
                        <button
                            type="button"
                            onClick={() => page('left')}
                            aria-label={t('carousel.prev')}
                            className={`${arrowBase} left-0 text-[var(--ev)] max-sm:-left-4 ${
                                edges.canLeft ? 'opacity-80 hover:opacity-100' : 'pointer-events-none opacity-20'
                            }`}
                        >
                            <Arrow flip />
                        </button>
                    )}

                    {/* Track: inset by the arrow gutters on ≥sm. Cards exactly fill it
                        so a page always lands on a fresh pair. */}
                    <div
                        ref={trackRef}
                        className={`flex snap-x snap-mandatory gap-5 overflow-x-auto scroll-smooth [scrollbar-width:none] sm:mx-12 [&::-webkit-scrollbar]:hidden ${
                            scrollable ? '' : 'justify-center'
                        }`}
                    >
                        {event.offers.map((offer) => {
                            // A typed badge wins over the derived percentage: the
                            // discount can express "20% off" and cannot express
                            // "two cartons and the third free".
                            const badge =
                                localized(offer, 'badge') ||
                                (offer.discount_percent ? t('storeEvent.percentOff', { n: offer.discount_percent }) : null);

                            return (
                                <Link
                                    key={offer.id}
                                    href={`/products/${offer.slug}`}
                                    className="group relative flex shrink-0 basis-[calc((100%_-_1.25rem)_/_2)] snap-start flex-col overflow-hidden rounded-[18px] bg-white shadow-sm transition hover:shadow-lg"
                                >
                                    <div className="relative aspect-[2/1] overflow-hidden bg-[var(--ev)]">
                                        {offer.image ? (
                                            <img
                                                src={offer.image}
                                                alt={localized(offer, 'name')}
                                                className="h-full w-full object-cover transition duration-500 group-hover:scale-[1.03]"
                                            />
                                        ) : (
                                            <div className="flex h-full w-full items-center justify-center text-5xl">🌴</div>
                                        )}

                                        {/* Reading-direction gradient, so the copy always
                                            sits over the dark end whichever way the page
                                            runs. `to left` in RTL is the start side. */}
                                        <div className="absolute inset-0 bg-gradient-to-l from-black/10 via-black/45 to-black/85 rtl:bg-gradient-to-r" />

                                        <div className="absolute inset-0 flex flex-col justify-end gap-1.5 p-4 text-start text-white sm:p-5">
                                            {badge && (
                                                <span className="font-heading w-fit rounded-full bg-[var(--ev)] px-2.5 py-1 text-[0.65rem] font-bold text-white shadow-sm sm:text-xs">
                                                    {badge}
                                                </span>
                                            )}
                                            <h3 className="font-heading line-clamp-2 text-[clamp(0.95rem,1.6vw,1.35rem)] font-black drop-shadow-md">
                                                {localized(offer, 'name')}
                                            </h3>
                                        </div>
                                    </div>

                                    <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 px-4 py-3 sm:px-5">
                                        <span className="font-heading inline-flex items-baseline gap-2">
                                            {offer.has_options ? (
                                                <span className="font-bold whitespace-nowrap text-[var(--ev)]">
                                                    {t('catalogue.fromPrice', { price: offer.effective_price.toFixed(2), currency })}
                                                </span>
                                            ) : (
                                                <>
                                                    <span className="text-[1.05rem] font-black whitespace-nowrap text-[var(--ev)]">
                                                        {offer.effective_price.toFixed(2)} {currency}
                                                    </span>
                                                    {offer.on_sale && (
                                                        <span className="text-brand-teal/45 text-sm whitespace-nowrap line-through">
                                                            {offer.price.toFixed(2)} {currency}
                                                        </span>
                                                    )}
                                                </>
                                            )}
                                        </span>

                                        {/* `dir` is on the pill itself, not the card: it
                                            carries no `ltr:` utilities, so scoping it here
                                            cannot switch a size variant on mid-Arabic. */}
                                        <span
                                            dir={i18n.language === 'ar' ? 'rtl' : 'ltr'}
                                            className="rounded-full bg-[color-mix(in_srgb,var(--ev)_10%,white)] px-2.5 py-1 text-[0.65rem] font-medium whitespace-nowrap text-[var(--ev)]"
                                        >
                                            {daysLeft > 0 ? t('storeEvent.endsIn', { n: daysLeft }) : t('storeEvent.endsToday')}
                                        </span>
                                    </div>
                                </Link>
                            );
                        })}
                    </div>

                    {scrollable && (
                        <button
                            type="button"
                            onClick={() => page('right')}
                            aria-label={t('carousel.next')}
                            className={`${arrowBase} right-0 text-[var(--ev)] max-sm:-right-4 ${
                                edges.canRight ? 'opacity-80 hover:opacity-100' : 'pointer-events-none opacity-20'
                            }`}
                        >
                            <Arrow />
                        </button>
                    )}
                </div>
            </div>
        </section>
    );
}

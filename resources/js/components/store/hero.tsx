import { Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { useLocalized } from '@/lib/localize';

/** One slide's copy, read from i18n (`hero.slides`). */
interface SlideCopy {
    key: string;
    line1: string;
    line2: string;
    subtext: string;
    cta: string;
}

/**
 * One slide's art and layout. Keyed by the copy entry's `key` rather than paired
 * by index, so reordering the i18n array (or a translator dropping an entry)
 * cannot silently hand a slide the wrong photograph.
 */
interface SlideArt {
    image: string;
    /** Portrait crop for phones. See PHONE ART below for what happens without one. */
    imageMobile?: string;
    href: string;
    /**
     * Which PHYSICAL side the copy sits on. This is dictated by where the subject
     * is baked into the photograph, so it must NOT mirror with reading direction:
     * `left-0`/`right-0`, never the logical `start-0`. Flipping it in English
     * would drop the headline straight onto the product.
     */
    copySide: 'left' | 'right';
    /**
     * Plate brightness, which decides the scrim's direction AND its colour. A
     * white wash over the dark-teal plate would grey it out; a dark wash over the
     * sand plates would muddy them.
     */
    tone: 'light' | 'dark';
    line1: 'teal' | 'white' | 'gold';
    cta: 'teal' | 'gold';
    /** Flanking rules beside the subtext. The three newer designs omit them. */
    rules?: boolean;
    /** object-position for the phone crop when there is no portrait art. */
    phoneFocus?: string;
}

/**
 * A campaign banner from a running store event (built by App\Support\HeroBanners,
 * managed on /admin/store-events). Headline, offer and CTA are baked into the
 * artwork, so it carries only alt text, in both locales.
 *
 * 🔑 While the server sends ANY banner, the hero shows banners and nothing else;
 * when the event ends (or its banners are switched off) the four copy slides
 * below come back untouched. See `slides` in StoreHero for why the two kinds
 * never share the carousel.
 */
interface HeroBanner {
    id: number;
    /** Desktop art, 2:1, served at the 1920px `hero` variant. */
    image: string;
    /** Phone art, 4:5 — null when the admin uploaded none. */
    image_mobile: string | null;
    href: string;
    alt_ar: string | null;
    alt_en: string | null;
}

type Slide = { kind: 'banner'; key: string; banner: HeroBanner } | { kind: 'copy'; key: string; copy: SlideCopy; art: SlideArt };

/**
 * ⚠️ PHONE ART — only `harvest` has a portrait crop. The other three are the
 * designer's 1440x800 landscape plates, and `object-cover` into the phone's
 * 402x804 box scales them by HEIGHT, so only ~28% of their width survives.
 * `phoneFocus` aims that slice at the subject, which keeps them presentable, but
 * it is a holding position: a tight crop of a landscape composition is not the
 * same picture the designer framed, and the copy band then sits over the subject
 * rather than over empty ground. Portrait crops (402x874) should be requested for
 * these three exactly as one was for `harvest` on 2026-08-15.
 *
 * 🔑 Every slide MUST resolve to the same rendered height or the carousel jumps
 * as it advances, which is why the fallback crops to the same 402/804 box rather
 * than using a gentler aspect per slide.
 */
const ART: Record<string, SlideArt> = {
    harvest: {
        image: '/images/hero/slide-1.webp',
        imageMobile: '/images/hero/slide-1-mobile.webp',
        href: '/shop',
        copySide: 'left',
        tone: 'light',
        line1: 'teal',
        cta: 'teal',
        rules: true,
    },
    gift: {
        image: '/images/hero/slide-2.webp',
        href: '/shop',
        copySide: 'left',
        tone: 'light',
        line1: 'white',
        cta: 'teal',
        // Subject (the man holding the basket) is centred near x=76% of 1440.
        phoneFocus: 'max-sm:object-[76%_50%]',
    },
    delivery: {
        image: '/images/hero/slide-3.webp',
        href: '/shop',
        copySide: 'right',
        tone: 'light',
        line1: 'teal',
        cta: 'teal',
        // The handed-over box sits left of centre, near x=28%.
        phoneFocus: 'max-sm:object-[28%_50%]',
    },
    occasion: {
        image: '/images/hero/slide-4.webp',
        href: '/shop',
        copySide: 'right',
        tone: 'dark',
        line1: 'gold',
        cta: 'gold',
        // The open dates box is centred near x=36%.
        phoneFocus: 'max-sm:object-[36%_50%]',
    },
};

/**
 * Where the desktop landscape crop stops working and the portrait one takes over.
 * 640px (Tailwind's `sm`): the 1440x800 desktop image renders only ~217px tall at
 * 390px wide — a sliver with the headline crammed into it — while the 402x874
 * portrait renders ~848px, about one phone screen, which is what it was cut for.
 */
const MOBILE_ART = '(max-width: 639px)';

/**
 * How long each slide holds, in ms.
 *
 * The brief was 5-8 seconds; 6 sits inside it. Worth not going shorter: each slide
 * carries two headline lines plus a sentence of subtext, and an Arabic reader needs
 * most of five seconds to get through it once.
 */
const SLIDE_MS = 6000;

/*
 * ⚠️ Every class below is a WHOLE literal string on purpose. Tailwind scans source
 * text, so a composed `bg-gradient-to-${dir}` compiles to no CSS at all and the
 * scrim silently disappears.
 */

/** Scrim runs from the copy's side, so the wash is densest under the text. */
const SCRIM: Record<string, string> = {
    'light-left': 'bg-gradient-to-r from-white/45 via-white/10 to-transparent max-sm:bg-gradient-to-b max-sm:from-white/55 max-sm:via-white/15',
    'light-right': 'bg-gradient-to-l from-white/45 via-white/10 to-transparent max-sm:bg-gradient-to-b max-sm:from-white/55 max-sm:via-white/15',
    // The dark plate needs deepening, not lightening — and a heavier hand on
    // phones, where the fallback crop puts the copy over the subject.
    'dark-left': 'bg-gradient-to-r from-black/40 via-black/10 to-transparent max-sm:bg-gradient-to-b max-sm:from-black/55 max-sm:via-black/20',
    'dark-right': 'bg-gradient-to-l from-black/40 via-black/10 to-transparent max-sm:bg-gradient-to-b max-sm:from-black/55 max-sm:via-black/20',
};

/** Copy column, anchored physically. `max-sm` resets it to the full top band. */
const COLUMN: Record<string, string> = {
    left: 'left-0 pr-4 pl-[5%] lg:-translate-x-4',
    right: 'right-0 pl-4 pr-[5%] lg:translate-x-4',
};

/**
 * White line1 carries the same two-layer shadow as line2: the sand plates are
 * uniformly light behind the copy, so white type has nothing to sit against.
 * Teal and gold read unaided on their own slides.
 */
const LINE1: Record<string, string> = {
    teal: 'text-brand-teal',
    gold: 'text-brand-gold',
    white: 'text-white [text-shadow:0_1px_3px_rgba(0,0,0,0.6),0_4px_16px_rgba(0,0,0,0.4)]',
};

/** The label stays white on both, so `.cta-shimmer`'s default base is correct. */
const CTA: Record<string, string> = {
    teal: 'bg-brand-teal hover:bg-brand-teal/90',
    gold: 'bg-brand-gold hover:bg-brand-gold/90',
};

const RULE: Record<string, string> = {
    teal: 'bg-brand-teal',
    gold: 'bg-brand-gold',
    white: 'bg-white',
};

/** Rounded-triangle carousel arrow (from Polygon 2.svg). Points right by default. */
function Arrow({ flip }: { flip?: boolean }) {
    return (
        <svg width="22" height="23" viewBox="0 0 24 25" fill="none" aria-hidden className={flip ? '-scale-x-100' : undefined}>
            <path
                d="M19.9167 6.95319C24.0648 9.34813 24.0648 15.3355 19.9167 17.7304L9.33334 23.8407C5.18519 26.2356 0 23.242 0 18.4521V6.23151C0 1.44165 5.18519 -1.55203 9.33333 0.842905L19.9167 6.95319Z"
                fill="white"
            />
        </svg>
    );
}

/**
 * A finished campaign banner: no overlay copy, no scrim, and the whole slide is
 * the link (the "shop now" pill is part of the artwork).
 *
 * 🔑 Rendered in the art's OWN shape (2:1 on desktop, 4:5 on phones), so nothing
 * is cropped and nothing is filled. That is only safe because banners never share
 * the carousel with the 1440/800 copy slides (see `slides` in StoreHero): mixed,
 * the hero would change height as it rotates and drag the page below with it.
 * The aspect is reserved on the img itself, so a lazy banner whose file has not
 * arrived yet still holds its box instead of collapsing the hero to 0px.
 *
 * Phones get the 4:5 phone art rather than the web banner: at 390px wide the 2:1
 * banner renders 195px tall with its small print around 6px, which nobody can
 * read. ⚠️ But only when EVERY banner in the set has phone art (`phonePoster`):
 * phone art is optional per banner, and a set mixing 4:5 posters with 2:1 banners
 * would change height on every other slide. Without a full set, phones show the
 * desktop art too, at its own 2:1.
 */
function BannerSlide({ banner, alt, priority, phonePoster }: { banner: HeroBanner; alt: string; priority: boolean; phonePoster: boolean }) {
    return (
        <Link href={banner.href} className="block bg-[#01482b]">
            <picture>
                {phonePoster && banner.image_mobile && <source media={MOBILE_ART} srcSet={banner.image_mobile} />}
                <img
                    // eager + high priority: the first banner is the homepage's LCP element.
                    fetchPriority={priority ? 'high' : 'auto'}
                    loading={priority ? 'eager' : 'lazy'}
                    src={banner.image}
                    alt={alt}
                    className={`block aspect-[2/1] w-full object-cover ${phonePoster ? 'max-sm:aspect-[4/5]' : ''}`}
                />
            </picture>
        </Link>
    );
}

export default function StoreHero() {
    const { t, i18n } = useTranslation();
    // `i18n.dir()` rather than reading document.dir: it resolves from the language
    // alone, so it is correct during SSR where there is no document.
    const rtl = i18n.dir() === 'rtl';

    const localized = useLocalized();

    // Homepage-only prop (ShopController::index). Each banner already carries its
    // own link — to the offer it advertises, or to its event's page.
    const { heroBanners } = usePage().props as { heroBanners?: HeroBanner[] };
    const banners = Array.isArray(heroBanners) ? heroBanners : [];
    // One phone shape for the whole set: posters only when every banner has one.
    const phonePoster = banners.length > 0 && banners.every((b) => b.image_mobile);

    const raw = t('hero.slides', { returnObjects: true, defaultValue: [] }) as unknown;
    const copy = (Array.isArray(raw) ? raw : []) as SlideCopy[];
    const bannerSlides = banners.map((banner): Slide => ({ kind: 'banner', key: `banner-${banner.id}`, banner }));
    const copySlides: Slide[] = [
        // Drop any copy entry with no matching art rather than rendering a slide with
        // a broken image, and keep i18n order as carousel order.
        ...copy.filter((c) => ART[c.key]).map((c): Slide => ({ kind: 'copy', key: c.key, copy: c, art: ART[c.key] })),
    ];
    /**
     * 🔑 Banners OR copy slides, never both (client decision, 2026-09-13). They are
     * different shapes — the banners 2:1 / 4:5, the copy slides 1440/800 / 402/804 —
     * and every slide in one carousel must share one height, or the page below
     * jumps as it rotates (~80px on a laptop, ~300px on a phone). The ways to mix
     * them were all worse: cropping a banner into 1.8:1 cut through its text,
     * letterboxing it left a visible filled strip, and making everything 2:1 would
     * crop the photo slides. Running banners alone lets each keep its own shape.
     */
    const slides = bannerSlides.length > 0 ? bannerSlides : copySlides;

    const [index, setIndex] = useState(0);
    const [paused, setPaused] = useState(false);
    // Bumped by every manual control, purely so the timer below restarts. See goTo.
    const [nudge, setNudge] = useState(0);
    const count = slides.length;

    /**
     * Every manual control goes through here so the timer restarts on ANY press.
     *
     * 🔑 The nonce is why this exists rather than just calling `setIndex`. Keying
     * the timer on `index` alone covers the arrows (they always move), but pressing
     * the dot for the slide already showing sets state to the value it already has
     * — React bails out, nothing re-renders, the effect never re-runs and the old
     * timer keeps counting. So one control in the row would silently not reset it.
     */
    const goTo = (next: (i: number) => number) => {
        setIndex(next);
        setNudge((n) => n + 1);
    };

    /**
     * Auto-advance.
     *
     * ⚠️ Keyed on `index` and `nudge`, which is what makes a manual press RESET
     * the clock rather than inherit whatever is left of the current tick — without
     * it, tapping an arrow half a second before a tick yanks the slide away again
     * immediately.
     */
    useEffect(() => {
        if (count < 2 || paused) return;
        // An auto-rotating carousel is exactly what this setting is about.
        if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return;

        const id = window.setTimeout(() => setIndex((i) => (i + 1) % count), SLIDE_MS);

        return () => window.clearTimeout(id);
    }, [index, nudge, paused, count]);

    // Don't rotate behind a tab nobody is looking at — otherwise someone returns
    // after ten minutes to a slide chosen by a timer rather than by them.
    useEffect(() => {
        const onVisibility = () => setPaused(document.hidden);
        document.addEventListener('visibilitychange', onVisibility);

        return () => document.removeEventListener('visibilitychange', onVisibility);
    }, []);

    if (slides.length === 0) return null;

    const active = Math.min(index, slides.length - 1);
    const current = slides[active];
    const many = slides.length > 1;
    const prev = () => goTo((i) => (i - 1 + slides.length) % slides.length);
    const next = () => goTo((i) => (i + 1) % slides.length);

    return (
        <section
            className="relative w-full overflow-hidden"
            /* Pause while someone is reading or tabbing through it. Hover alone is
               not enough: a keyboard user never triggers it, and the arrows and dots
               are focusable. Together with the reduced-motion opt-out this is the
               mitigation for WCAG 2.2.2 short of a visible pause button, which a hero
               this size cannot carry without becoming furniture. */
            onMouseEnter={() => {
                // 🔴 Gated on a device that actually HOVERS. A tap dispatches a
                // synthetic `mouseenter` with no matching `mouseleave` (measured:
                // tapping an arrow fires mouseenter, and nothing ever releases it),
                // so on a phone the carousel paused permanently the first time
                // anyone touched it — which is most of this store's traffic. Same
                // trap as `.spotlight-card` in app.css, which is scoped the same way.
                if (window.matchMedia?.('(hover: hover)').matches) setPaused(true);
            }}
            onMouseLeave={() => setPaused(false)}
            onFocusCapture={(e) => {
                // 🔴 `:focus-visible`, NOT plain focus. Clicking a dot or an arrow
                // focuses it, so a bare focus handler paused the carousel PERMANENTLY
                // after any interaction — moving the mouse away does not blur. Caught
                // by measuring: 7.5s after a dot click it had still not advanced.
                // `:focus-visible` is exactly the distinction wanted here: a keyboard
                // user tabbing in gets it, a mouse click does not.
                if ((e.target as HTMLElement).matches?.(':focus-visible')) setPaused(true);
            }}
            onBlurCapture={() => setPaused(false)}
        >
            {current.kind === 'banner' ? (
                <BannerSlide banner={current.banner} phonePoster={phonePoster} alt={localized(current.banner, 'alt')} priority={active === 0} />
            ) : (
                <CopySlide slide={current.copy} art={current.art} priority={active === 0} />
            )}

            {/* Carousel arrows (only when there's more than one slide).
                🔑 The BUTTONS are physical (a left-pointing arrow is always on the
                left) but which slide each one reaches is DIRECTIONAL: "next" lies
                leftward in Arabic and rightward in English. So the handler and the
                label are chosen from the reading direction, not hardcoded.
                This was latent until now — with a single slide `many` was false and
                the arrows never rendered, so nothing exercised it. Same class of bug
                as the product-carousel arrows on 2026-08-15, where `scrollLeft`'s
                sign under RTL made the enabled arrow the one that did nothing. */}
            {many && (
                <>
                    <button
                        type="button"
                        onClick={rtl ? next : prev}
                        aria-label={rtl ? t('hero.nextSlide') : t('hero.prevSlide')}
                        className="absolute top-1/2 left-4 -translate-y-1/2 opacity-70 transition-opacity hover:opacity-100"
                    >
                        <Arrow flip />
                    </button>
                    <button
                        type="button"
                        onClick={rtl ? prev : next}
                        aria-label={rtl ? t('hero.prevSlide') : t('hero.nextSlide')}
                        className="absolute top-1/2 right-4 -translate-y-1/2 opacity-70 transition-opacity hover:opacity-100"
                    >
                        <Arrow />
                    </button>

                    {/* Dots. On phones a banner's small print (the offer's end date)
                        runs along its bottom edge, and dots laid over the art sat right
                        on it. So for banners on phones the dots drop BELOW the art into
                        their own row, teal because they now sit on the page background
                        rather than on the image. Desktop keeps them on the art, where
                        the banner's floor is empty at centre-bottom. */}
                    <div
                        className={`flex items-center gap-2 ${
                            current.kind === 'banner'
                                ? // `bottom-[4%]`, not a fixed 20px: lifted off the section's
                                  // edge (client-asked), and a percentage keeps the same place
                                  // on the art at every width. Clear of the products on all
                                  // five; the tightest is the diet tray, whose bottom sits at
                                  // ~93% of the banner's height.
                                  'absolute bottom-[4%] left-1/2 -translate-x-1/2 max-sm:static max-sm:translate-x-0 max-sm:justify-center max-sm:py-3'
                                : 'absolute bottom-5 left-1/2 -translate-x-1/2'
                        }`}
                    >
                        {slides.map((s, i) => (
                            <button
                                key={s.key}
                                type="button"
                                onClick={() => goTo(() => i)}
                                aria-label={`${t('hero.goToSlide')} ${i + 1}`}
                                className={`rounded-full bg-white transition-all ${current.kind === 'banner' ? 'max-sm:bg-brand-teal' : ''} ${
                                    i === active ? 'size-3 opacity-90' : 'size-2 opacity-50 hover:opacity-75'
                                }`}
                            />
                        ))}
                    </div>
                </>
            )}
        </section>
    );
}

/** A photograph with live, translated headline copy laid over it. */
function CopySlide({ slide, art, priority }: { slide: SlideCopy; art: SlideArt; priority: boolean }) {
    return (
        <>
            {/* <picture>, not two <img> toggled with `hidden`: the browser picks ONE
                source and downloads only that, so a phone never pulls the desktop
                crop it isn't going to show. */}
            {/* On phones the portrait crop is trimmed from the TOP rather than
                shipped at its full 874px, which was slightly taller than a phone
                screen once the navbar is counted. Done in CSS (aspect + cover +
                bottom anchor) rather than by re-cutting the file: lossless,
                reversible, and the amount stays tunable in one number.

                🔑 The trim comes off the top because the subject is anchored at
                the BOTTOM — but that same top band is the empty sand the copy
                sits in, so every pixel removed is a pixel of copy headroom
                spent. Measured: the man and the fire begin at y=371 of 874, and
                the English copy needs 220px at a 320px viewport, leaving only
                ~95px of theoretical slack. 70 is taken, not 95, so the tightest
                case keeps a real margin instead of just clearing.
                402x874 → 402x804. If this is ever re-tuned, re-measure the
                clearance at 320px in ENGLISH — it is the binding case, not
                Arabic and not the common widths. */}
            <picture>
                {art.imageMobile && <source media={MOBILE_ART} srcSet={art.imageMobile} />}
                <img
                    // eager + high priority: this is the LCP element on the homepage.
                    fetchPriority={priority ? 'high' : 'auto'}
                    loading={priority ? 'eager' : 'lazy'}
                    src={art.image}
                    alt=""
                    // 🔴 `aspect-[1440/800]`, not bare `h-auto`: every slide after the
                    // first is lazy-loaded, and until its image arrives an `h-auto`
                    // img is 0px tall, so the whole hero collapsed and the page below
                    // jumped up (measured: 0px on a dot press to an unloaded slide).
                    // All four plates are 1440x800, so reserving that box changes
                    // nothing once they load.
                    className={`block aspect-[1440/800] h-auto w-full object-cover max-sm:aspect-[402/804] ${
                        art.imageMobile ? 'max-sm:object-bottom' : (art.phoneFocus ?? 'max-sm:object-center')
                    }`}
                />
            </picture>

            {/* Scrim direction follows where the copy sits; its colour follows the
                plate. Measured on the portrait crop: uniform sand to ~39% of its
                height (row sd 13-24), then sd jumps to ~49 where the subject
                begins — so the copy has to stay inside that top band. */}
            <div className={`pointer-events-none absolute inset-0 ${SCRIM[`${art.tone}-${art.copySide}`]}`} />

            {/* Content block — physically anchored to the side the photograph
                leaves empty, text aligned per reading direction inside it.
                `lg:±translate-x-4` nudges the block slightly inward on laptop+
                screens (≥1024px), a small enough fixed offset (16px) that it's
                harmless at any width.
                The vertical nudge is `lg:-translate-y-[6.5vw]`, NOT a fixed value
                — the image is aspect-locked (`w-full h-auto`, no max-width), so
                its rendered height scales linearly with viewport width (≈1067px
                tall at 1920px wide, but only ≈759px at 1366px). A fixed px/rem
                shift is a small fraction of the image at 1920px+ but eats a much
                bigger share of it on a narrower laptop, which clips the headline
                against the section's top edge (overflow-hidden crops rather than
                reflows). `vw` keeps the shift exactly proportional to the image's
                own height at every width, so retune by adjusting the vw number,
                not by switching back to a fixed unit. */}
            {/* Desktop: a column beside the baked-in subject. Phones: the full
                width of the top 37% band. */}
            {/* 38% → 37%: the band is a fraction of the section, and trimming 70px
                off the top moved the subject from 42.4% to 37.4% of what remains.
                The two track together — change the crop, recompute this. */}
            <div
                className={`absolute inset-y-0 flex w-[56%] min-w-[300px] items-center max-sm:inset-y-auto max-sm:top-0 max-sm:h-[37%] max-sm:w-full max-sm:min-w-0 max-sm:justify-center max-sm:px-6 lg:-translate-y-[6.5vw] ${COLUMN[art.copySide]}`}
            >
                <div className="w-full text-start max-sm:text-center">
                    {/* Below 732px the kashida-elongated headline overflows the
                        narrow text column, so step the size down there and smaller. */}
                    {/* ⚠️ The old shrink steps here were tuned for the DESKTOP crop
                        squeezed onto a phone (217px tall at 390px wide) and are far too
                        small now that phones get a full-height portrait image, so `max-sm`
                        re-enlarges everything below 640px — the exact range where the
                        portrait art is in play.
                        🔑 There is deliberately no `max-[480px]` step any more: measured at
                        460px, the h1 computes to 34.5px, which is max-sm's value and not
                        the 32px an active max-[480px] rule would give. The named `max-sm`
                        variant is emitted AFTER both arbitrary ones (max-[732px] and
                        max-[480px]) and wins throughout, so any such rule would be dead
                        code. Below 640 there is now one size set, not three. */}
                    {/* Desktop ramp is 4.9vw, down from 6.2vw. At 6.2vw the English
                        lines needed ~0.604 of the viewport width but the column only
                        offers ~0.51 of it, so they could NEVER fit at any width and both
                        wrapped with an orphaned last word ("land", "table") — the same
                        broken lockup the phone had. Widening the column instead is not
                        available: the subject in the landscape crop starts at x=58.3%
                        (measured by per-column variance) and the column already runs to
                        56%. Arabic was unaffected either way, being far shorter. */}
                    {/* 🔑 `rtl:leading-[1.28]`. The two lines were nearly touching in
                        Arabic and comfortably separated in English at the SAME leading,
                        which is not a taste difference — it is measurable. At 1.08 the
                        gap from line 1's ink bottom to line 2's ink top is +17.2px in
                        English and −2.8px in Arabic, i.e. the Arabic glyphs actually
                        OVERLAP. Arabic fills far more of its line box: deep descenders
                        (ر, ك) under line 1 meeting tall ascenders (ل, أ, ك) on line 2,
                        where Latin leaves the same band empty.
                        1.28 buys Arabic ~0.15em of clear air at every width (10.6px at
                        1440, 4.4px at 390) — short of full parity with English's 0.24em
                        on purpose, because the headline is two lines of ~70px type and
                        matching it outright would add ~38px to a hero whose height is
                        already the thing everyone complains about. Measure the INK, not
                        the line boxes: the em box says these are 1.08 apart either way. */}
                    <h1 className="font-heading text-[clamp(2.25rem,4.9vw,5.5rem)] leading-[1.08] font-black max-[732px]:text-[clamp(1.35rem,4.5vw,2rem)] max-sm:text-[clamp(1.6rem,7.5vw,2.6rem)] rtl:leading-[1.28]">
                        <span className={`block ${LINE1[art.line1]}`}>{slide.line1}</span>
                        <span className="block text-white [text-shadow:0_1px_3px_rgba(0,0,0,0.6),0_4px_16px_rgba(0,0,0,0.4)]">{slide.line2}</span>
                    </h1>

                    {/* Subtext + CTA share a shrink-to-fit column sized to the subtext;
                        the CTA is centred under it. `text-center` is direction-agnostic,
                        so it behaves identically in Arabic (RTL) and English (LTR). */}
                    <div className="mt-5 inline-block max-w-full">
                        <div className="flex items-center gap-3">
                            {/* Flanking rules — first child sits on the start side
                                (right in RTL), the last on the end side. Only the
                                original slide has them; the newer designs don't. */}
                            {art.rules && (
                                <span
                                    className={`hidden h-1.5 w-[clamp(2rem,5vw,4.7rem)] shrink-0 rounded-full min-[733px]:block ${RULE[art.line1]}`}
                                />
                            )}
                            {/* White per the request. The sand plates are uniformly
                                light behind this whole column (no dark area to sit
                                white text on) and the scrim was tuned to lighten the
                                photo FOR dark text — which works against white text.
                                A two-layer text-shadow (tight dark edge + a soft wide
                                falloff) compensates without touching the scrim, which
                                line1 and the CTA still rely on. */}
                            <p className="font-heading text-[clamp(0.95rem,1.9vw,1.63rem)] text-white [text-shadow:0_1px_3px_rgba(0,0,0,0.6),0_4px_16px_rgba(0,0,0,0.4)] max-[732px]:text-[0.8rem] max-sm:max-w-none max-sm:text-[0.95rem]">
                                {slide.subtext}
                            </p>
                            {art.rules && (
                                <span
                                    className={`hidden h-1.5 w-[clamp(2rem,5vw,4.7rem)] shrink-0 rounded-full min-[733px]:block ${RULE[art.line1]}`}
                                />
                            )}
                        </div>

                        <div className="mt-7 text-center">
                            <Link
                                href={art.href}
                                className={`font-heading inline-block rounded-full px-10 py-4 text-[clamp(1.15rem,2.6vw,2.5rem)] font-black text-white transition-colors max-[732px]:px-6 max-[732px]:py-2.5 max-[732px]:text-[0.9rem] max-sm:px-7 max-sm:py-3 max-sm:text-[1rem] ${CTA[art.cta]}`}
                            >
                                <span className="cta-shimmer">{slide.cta}</span>
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}

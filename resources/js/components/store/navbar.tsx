import SearchOverlay from '@/components/store/search-overlay';
import { useLanguage } from '@/contexts/LanguageContext';
import { useLocalized } from '@/lib/localize';
import { Link, router, usePage } from '@inertiajs/react';
import { LogOut, Menu, Search, ShoppingBag, User, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useTranslation } from 'react-i18next';

interface NavCategory {
    id: number;
    name_ar: string;
    name_en: string | null;
    slug: string;
    children: { id: number; name_ar: string; name_en: string | null; slug: string }[];
}

interface NavEvent {
    id: number;
    name_ar: string;
    name_en: string | null;
}

interface SharedProps {
    navCategories?: NavCategory[];
    navEvents?: NavEvent[];
    cart?: { count?: number };
    auth?: { user?: unknown };
    hasOffers?: boolean;
    whatsappAuth?: boolean;
    [key: string]: unknown;
}

/** The teal filled caret from the Figma (Polygon 2). */
function Caret() {
    return (
        <svg width="9" height="8" viewBox="0 0 13 12" fill="none" aria-hidden className="text-brand-teal">
            <path
                d="M8.06506 10.5C7.29526 11.8333 5.37076 11.8333 4.60096 10.5L0.270827 3C-0.498974 1.66666 0.463279 0 2.00288 0L10.6631 0C12.2027 0 13.165 1.66667 12.3952 3L8.06506 10.5Z"
                fill="currentColor"
            />
        </svg>
    );
}

/**
 * Sign-out glyph, shared by the desktop dropdown and the mobile drawer.
 *
 * 🔑 The RED LIVES HERE AND NOWHERE ELSE. Signing out is worth marking as an exit
 * rather than a destination, but a fully red row (or worse, a red-bordered
 * red-tinted button) reads as an error state and is the loudest thing in a drawer
 * of quiet gold links. Confining the warning colour to a 16px glyph gives the same
 * signal at a fraction of the volume, and lets the label sit in brand teal with
 * the rest of the storefront.
 *
 * ⚠️ Mirrored under RTL. The lucide mark is a door with the arrow pointing OUT to
 * the right, which is a direction, not a shape — in Arabic "out" reads leftward,
 * so unmirrored it points back into the page. Same treatment as the footer's
 * HardRock arrow. (Note Tailwind v4 emits this as the standalone `scale` property,
 * so `getComputedStyle().transform` reads `none` while the flip is working.)
 */
function LogOutIcon() {
    return <LogOut className="size-4 shrink-0 text-red-500 rtl:-scale-x-100" aria-hidden />;
}

/*
 * Header scroll behaviour: it slides away with the page from the first pixel of
 * downward scroll. Coming back is deliberately harder than going away — it takes
 * REVEAL_AFTER px of sustained upward scrolling and then waits REVEAL_DELAY_MS
 * before it starts moving, so it cannot flicker in and out during ordinary reading.
 *
 * 🔴 The header's HEIGHT IS NOW CONSTANT, and that is load-bearing, not a style
 * choice. It used to collapse its padding (~40px) once scrolled, and because the
 * header is `sticky` (still in normal flow) that shortened the whole page, which
 * the browser's scroll anchoring compensated for by *reducing* scrollY by about
 * the same amount. The direction detector below then read that synthetic jump as
 * a genuine scroll UP and re-revealed the header — which is why one wheel notch
 * used to land on "compact and visible" instead of "hidden", and why a second
 * notch was needed to actually hide it. Measured: from y=0, one 120px notch ended
 * at y=80 with the header re-shown.
 *
 * So a height change cannot coexist with direction-based hide/reveal while the
 * header is in flow. Anything reintroducing a collapse, a growing announcement
 * bar, or any other in-flow height change here will bring the artifact back;
 * animate colour/shadow/opacity instead, which are layout-neutral.
 *
 * SHADOW_AT needs no hysteresis for the same reason: a shadow does not affect
 * layout, so it cannot feed back into scroll position.
 *
 * 🔴 WHY IT TRAVELS WITH THE SCROLL INSTEAD OF STAYING PINNED: being `sticky` the
 * header keeps its box in normal flow, and a transform does not remove that box. So
 * translating it away while the viewport is still inside that box just exposes the
 * box — a blank strip of page background above the content (measured: at y=60 the
 * element at the viewport top was the page wrapper, not the hero).
 *
 * That used to be handled by PINNING the header open for its own height (~130px on
 * desktop), which is why hiding it took an unnaturally long scroll: a 120px wheel
 * notch from the top did nothing at all.
 *
 * It now translates by exactly `-scrollY` while inside that band. Its box sits at
 * page 0..H, i.e. viewport -y..H-y, and the header offset by -y lands on precisely
 * the same rectangle, so it COVERS its own box at every position instead of
 * revealing it. Visually that is just a normal header scrolling away, and it starts
 * on the first pixel. Past H the offset caps and it is simply gone, so the handoff
 * to the hidden state is continuous rather than a jump.
 *
 * ⚠️ The offset is written straight to the node in the rAF callback, NOT held in
 * React state: a per-pixel value in state would re-render the whole header on every
 * scroll frame. React still owns `scrolled` (the shadow), which flips rarely.
 *
 * ⚠️ Two earlier attempts at deciding when to animate were both wrong, so the rule
 * below is derived from the constraint rather than guessed at:
 *
 *   1. Comparing the offset delta against the SCROLL delta. A fast flick has a big
 *      delta, so a reveal measured as "smaller than the scroll", was treated as
 *      tracking, and snapped in with no animation — worse the faster you scrolled.
 *   2. Skipping the transition for the whole tracking phase. That made every hide
 *      from the top a rigid 1:1 follow with no easing, which reads as the header
 *      vanishing instantly while animating properly further down the page.
 *   3. Comparing against the last TARGET rather than the element's rendered
 *      position. Correct as a rule, applied to the wrong quantity: during the
 *      reveal's 750ms delay the target and the render disagree by the full band, so
 *      a fast scrollbar drag from far down the page left the header parked
 *      off-screen while the scroll position fell inside the band. See `rendered`.
 *
 * The actual rule: animating is safe whenever the header moves FURTHER OUT of view,
 * because the destination is clamped to `min(y, band)` and y only grows while
 * scrolling down, so the eased value trails the target which trails the scroll and
 * `T <= y` holds throughout. It is unsafe only when the header must come back DOWN
 * while already above the scroll position, which is the one case that snaps.
 */
const SHADOW_AT = 8;

/** Floor for the travel band, in case the height measurement is unavailable. */
const MIN_TRAVEL_BAND = 24;

/** Minimum scroll delta before a direction change counts, to ignore jitter. */
const DIRECTION_DELTA = 4;

/**
 * Cumulative UPWARD distance required before the header comes back, in px.
 *
 * A single wheel notch is ~100-120px in Chrome, so one notch used to be enough and
 * the header reappeared on the smallest correction. 180px is deliberately about one
 * and a half notches: enough to require intent to go back up, not a nudge.
 *
 * It has to be an ACCUMULATOR rather than a bigger DIRECTION_DELTA. A per-event
 * threshold would break trackpads, which emit a stream of small deltas — a long
 * two-finger swipe would never produce one event past the threshold and the header
 * could never come back at all. Summing consecutive upward movement treats a fast
 * wheel flick and a slow trackpad drag the same.
 */
const REVEAL_AFTER = 180;

/**
 * Delay before the reveal starts moving. Applied as a CSS transition-delay on the
 * reveal only (never on the hide, which must feel immediate), so a brief scroll-up
 * mid-gesture does not flash the header: if the direction flips back down inside
 * the delay, the target changes before the element has moved at all.
 */
const REVEAL_DELAY_MS = 750;

/**
 * Signed-in account destinations, shared by the desktop dropdown and the mobile
 * drawer so the two cannot drift. Order is by how often a customer wants them.
 */
const ACCOUNT_LINKS = [
    { href: '/account', key: 'common.myAccount' },
    { href: '/account/profile', key: 'account.editProfile' },
    // ⚠️ `/wishlist`, NOT `/account/wishlist`. The route is registered at the bare
    // path (routes/web.php, `wishlist.index`), so the prefixed guess 404'd for every
    // signed-in customer from both the dropdown and the drawer. The account
    // dashboard already linked here correctly, which is why it went unnoticed.
    { href: '/wishlist', key: 'account.wishlist' },
] as const;

export default function StoreNavbar() {
    const { t } = useTranslation();
    const { toggleLanguage } = useLanguage();
    const localized = useLocalized();
    const page = usePage();
    const props = page.props as SharedProps;
    const url = page.url;

    const navCategories = props.navCategories ?? [];
    const hasOffers = Boolean(props.hasOffers);
    // Running campaigns, each its own nav item under its OWN name. The row simply
    // has nothing to map over when none is running, so it hides itself — no flag.
    const navEvents: NavEvent[] = Array.isArray(props.navEvents) ? props.navEvents : [];
    const cartCount = props.cart?.count ?? 0;
    const loggedIn = Boolean(props.auth?.user);
    /*
     * 🔴 Every sign-in affordance in the storefront resolves through here, so while
     * WhatsApp cannot deliver a code this MUST NOT point at the OTP page — that was
     * the store's default sign-in path leading to a form that silently could not
     * work (the log driver reports sends as successful, so the customer reached a
     * code field and waited forever).
     *
     * Driven by the shared prop rather than hardcoded to `/login`, so switching
     * WHATSAPP_DRIVER=cloud restores the intended flow with no code change and
     * nothing to remember to undo at launch.
     */
    const accountHref = loggedIn ? '/account' : props.whatsappAuth ? '/login/whatsapp' : '/login';

    const [mobileOpen, setMobileOpen] = useState(false);
    const [searchOpen, setSearchOpen] = useState(false);

    // Reveal-on-scroll-up navbar: one scroll down hides it outright, scrolling up
    // slides it straight back in, so navigation is always a flick away. `scrolled`
    // now drives ONLY the drop shadow — see the note above the constants.
    const [scrolled, setScrolled] = useState(false);
    const headerRef = useRef<HTMLElement>(null);

    useEffect(() => {
        const header = headerRef.current;
        if (!header) return;

        let lastY = window.scrollY;
        let ticking = false;
        let revealed = true;
        let offset = 0;
        // Running total of consecutive upward movement, reset the moment the user
        // scrolls down again so slow jitter can never creep up to the threshold.
        let upward = 0;
        // Cached, not read per frame: reading offsetHeight inside the scroll
        // handler would force a layout flush on every tick. The height is constant
        // now, so it only needs re-measuring when the breakpoint changes.
        let band = MIN_TRAVEL_BAND;
        const measure = () => {
            band = Math.max(header.offsetHeight, MIN_TRAVEL_BAND);
        };
        measure();

        const update = () => {
            const y = window.scrollY;
            setScrolled(y > SHADOW_AT);

            if (y > lastY + DIRECTION_DELTA) {
                revealed = false; // scrolling down → let it go
                upward = 0;
            } else if (y < lastY - DIRECTION_DELTA) {
                // Reveal is earned, not immediate: it takes REVEAL_AFTER px of
                // sustained upward scrolling, roughly two wheel notches.
                upward += lastY - y;
                if (upward >= REVEAL_AFTER) revealed = true;
            }

            // Capped at the band: past its own height the header is fully gone, and
            // clamping here is what makes the tracking phase hand over to the hidden
            // state without a visible step.
            const next = revealed ? 0 : Math.min(y, band);

            // 🔴 The element's ACTUAL translate, which is NOT the same as the last
            // target we asked for — and conflating the two is what exposed the strip
            // on a fast scrollbar drag. A drag up from far down the page fires its
            // first event outside the band, which flips `revealed`, sets the target to
            // 0 and starts the reveal's 750ms DELAY. The element has not moved at all
            // yet, but `offset` already said 0, so the guard below could never fire
            // again — and the header sat parked at -band while the scroll position
            // fell inside the band. Measured before the fix: 54px of page background
            // at y=60, 94px at y=20, for the remainder of the delay.
            //
            // ⚠️ Reading this forces a layout flush, so it is read ONLY where it can
            // matter. Once y >= band the rendered value is capped at band <= y, so no
            // amount of transition lag can violate the invariant below and the cached
            // target is a fine stand-in. That keeps the read inside the first ~114px
            // of the page.
            const rendered = y < band ? -header.getBoundingClientRect().top : offset;

            // 🔑 The ONE condition that has to hold, from which everything else
            // follows: the header may never be translated further up than the page
            // has scrolled. Its `sticky` box sits at page 0..H, so at scroll y the
            // still-visible part of that box is viewport 0..(H-y); a translate of T
            // covers 0..(H-T), which leaves a blank strip exactly when T > y.
            //
            // Animating is therefore SAFE whenever the header is moving further out
            // of view, because the destination is already clamped to `min(y, band)`
            // and y only grows while scrolling down — so the eased value trails the
            // target, which trails the scroll.
            //
            // It is UNSAFE only when the header has to come back DOWN while it is
            // already above the scroll position (the page scrolled up inside the
            // band). There the eased value would sit above y for the duration of the
            // animation and expose the strip, so that move snaps instead.
            //
            // Against the RENDERED value this is now provably complete rather than
            // merely careful: `next` is always <= y (it is `0` or `min(y, band)`), so
            // whenever the invariant is violated — rendered > y — it follows that
            // next <= y < rendered, which is exactly this condition. Any violation is
            // therefore corrected in the same frame it is detected.
            const instant = next < rendered && rendered > y;

            // The delay is on the REVEAL only. A hide has to feel immediate, and
            // delaying it would leave the header sitting over content the user has
            // already scrolled past. The hide is also quicker than the reveal, so it
            // keeps up with the page instead of appearing to float behind it.
            const duration = revealed ? 400 : 260;
            const delay = revealed ? ` ${REVEAL_DELAY_MS}ms` : '';
            header.style.transition = instant
                ? 'box-shadow 300ms ease'
                : `transform ${duration}ms ease-out${delay}, opacity ${duration}ms ease-out${delay}, box-shadow 300ms ease`;
            header.style.transform = `translate3d(0, ${-next}px, 0)`;
            header.style.opacity = next >= band ? '0' : '1';
            // Only unreachable once it is entirely off-screen; while it is mid-travel
            // it is still partly visible and must stay clickable.
            header.style.pointerEvents = next >= band ? 'none' : '';

            offset = next;
            lastY = y;
            ticking = false;
        };
        const onScroll = () => {
            if (!ticking) {
                ticking = true;
                requestAnimationFrame(update);
            }
        };
        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', measure);
        return () => {
            window.removeEventListener('scroll', onScroll);
            window.removeEventListener('resize', measure);
        };
    }, []);

    const isActive = (href: string) => (href === '/' ? url === '/' : url.startsWith(href));
    const isCategoryActive = (slug: string) => url.includes(`category=${slug}`);

    // Shared classes for a top-level nav link.
    const linkBase = 'rounded-full px-4 py-1.5 text-sm font-medium transition-colors';
    const linkIdle = 'text-brand-gold hover:text-brand-teal';
    const linkActive = 'bg-[#d9d9d9]/25 text-brand-teal';

    return (
        <header ref={headerRef} className={`border-brand-gold/10 sticky top-0 z-40 border-b bg-white ${scrolled ? 'shadow-md' : ''}`}>
            {/* Faint knot watermark (LOGO 2), clipped to the header via its own
                overflow-hidden wrapper so it never clips the nav dropdowns. */}
            <div className="pointer-events-none absolute inset-0 overflow-hidden">
                <img
                    src="/images/brand/navbar-pattern.png"
                    alt=""
                    aria-hidden
                    className="absolute end-0 top-0 h-full w-auto opacity-70 select-none"
                />
            </div>

            <div className="relative mx-auto max-w-[1600px] px-4 sm:px-6 lg:px-12">
                {/* Row 1 — utility icons · logo · language. The padding is a FIXED
                    value on purpose, never a scroll-dependent one; see the constants
                    note about in-flow height changes. Trimming the static number is
                    fine, and it shortens the travel band too (the band IS the
                    header's measured height), so the header also hides sooner. */}
                <div className="grid grid-cols-3 items-center py-2.5">
                    {/* Start: utility icons (desktop) / hamburger + sign-up (mobile) */}
                    <div className="flex items-center gap-1.5 justify-self-start min-[430px]:gap-2 sm:gap-3 md:gap-4">
                        <button
                            type="button"
                            onClick={() => setMobileOpen(true)}
                            aria-label={t('nav.menu')}
                            className="text-brand-gold hover:text-brand-teal shrink-0 transition-colors md:hidden"
                        >
                            <Menu className="size-6" />
                        </button>
                        {/* Opens the site-wide search overlay. It used to be a plain
                            link to /shop — the only place a search box existed — so a
                            shopper on a product page had to leave it to search, and
                            lost where they were.
                            Sits beside the burger at EVERY width: on desktop it leads
                            the utility icons, on phones it is the first thing after the
                            menu. See the sign-up pill below for why it goes HERE rather
                            than after the pill — search is a utility, the pill is an
                            invitation, and the utilities cluster by the burger. */}
                        <button
                            type="button"
                            onClick={() => setSearchOpen(true)}
                            data-testid="nav-search"
                            aria-label={t('nav.search')}
                            /* ⚠️ `p-1 -m-1`, not a bigger icon. At size-5 the hit area
                               was 20x20 — under the 24px WCAG 2.5.8 floor and smaller
                               than the 24px burger beside it. The padding grows the
                               touch target to 28px while the negative margin cancels
                               its layout cost, which matters because the start cell
                               has only ~9px of clearance to the logo at 390px in
                               Arabic. 4px of the 6px gap is absorbed, so the two hit
                               boxes still do not overlap. */
                            className="text-brand-gold hover:text-brand-teal -m-1 inline-flex shrink-0 p-1 transition-colors"
                        >
                            <Search className="size-5" />
                        </button>
                        {/* Mobile sign-up, beside the burger.
                            🔑 Sign-UP was the one auth action a phone visitor could
                            only reach by opening the drawer — the end cell's User icon
                            goes to sign-IN. So a new customer had to guess that
                            "create an account" lived behind a hamburger.
                            Filled and labelled rather than a third icon, because it is
                            an invitation, not a utility; that is the same reasoning as
                            the desktop pill, whose place in the end cell it takes on
                            phones (there is no room for it beside the cart). */}
                        {!loggedIn && (
                            <Link
                                href="/register"
                                data-testid="nav-signup-mobile"
                                /*
                                 * ⚠️ `max-[359px]:hidden` is measured, not a guess.
                                 * Row 1 is a 3-column grid so the logo is
                                 * mathematically centred, which means each cell gets
                                 * exactly a third — 90.7px at a 320px viewport. The
                                 * burger plus this pill need 116, so the start cell
                                 * overruns its column and lands ON the logo (19px of
                                 * overlap in Arabic). No text short enough to fit 56px
                                 * is still readable.
                                 *
                                 * The alternative was auto-sized columns, which stops
                                 * the collision but pushes the logo ~20px off centre
                                 * at EVERY width to fix one rare one. Hiding here
                                 * costs nothing instead: 320px phones are where this
                                 * button already was before today, and the drawer two
                                 * pixels away still carries it.
                                 */
                                className="bg-brand-teal shrink-0 rounded-full px-2 py-1 text-xs font-bold whitespace-nowrap text-white transition-colors hover:bg-[#163e42] max-[389px]:hidden min-[430px]:px-2.5 sm:px-3 md:hidden"
                            >
                                {/* ⚠️ A SHORTER label than the desktop pill, and the
                                    difference is load-bearing rather than cosmetic:
                                    "Create account" measured 111px, which drove the
                                    start cell past its grid column and straight over
                                    the centred logo (46px of overlap at 320). Same
                                    trick, same reason as the EN/AR language toggle
                                    below `md`. Arabic is already short enough that its
                                    two values are identical. */}
                                {t('nav.signUpShort')}
                            </Link>
                        )}
                        {/* Account. Signed out it is a plain link to sign-in (the pill
                            in the end cell carries the sign-up); signed in it opens a
                            menu, which is also the only place in the header a customer
                            could previously log out from — there wasn't one. */}
                        {loggedIn ? (
                            /* ⚠️ `md:flex md:items-center`, NOT `md:block`. Its child
                               is an inline-flex button, so a block wrapper puts it in a
                               LINE BOX and the strut's descent adds ~6px of space
                               beneath the baseline-aligned button. The row centres the
                               wrapper, so that dead space pushed the icon visibly ABOVE
                               its neighbours (measured: wrapper 26px vs 20px, icon
                               centre 31 vs 34). Making the wrapper a flex container
                               blockifies the button and removes the line box entirely.
                               The sibling icons never had this because they are direct
                               flex items of the row. */
                            <div className="group relative hidden md:flex md:items-center">
                                <button
                                    type="button"
                                    aria-label={t('common.myAccount')}
                                    className="text-brand-gold hover:text-brand-teal inline-flex items-center gap-1 transition-colors"
                                >
                                    <User className="size-5" />
                                    <Caret />
                                </button>
                                {/* Same hover/focus-within mechanism as the category
                                    dropdowns in row 2. `start-0` so it opens inward in
                                    both directions instead of off the edge. */}
                                <div className="border-brand-gold/15 invisible absolute start-0 top-full z-20 min-w-52 -translate-y-1 rounded-xl border bg-white p-2 opacity-0 shadow-lg transition-all group-focus-within:visible group-focus-within:translate-y-0 group-focus-within:opacity-100 group-hover:visible group-hover:translate-y-0 group-hover:opacity-100">
                                    {ACCOUNT_LINKS.map((l) => (
                                        <Link
                                            key={l.href}
                                            href={l.href}
                                            className={`block rounded-lg px-3 py-2 text-sm transition-colors ${
                                                isActive(l.href)
                                                    ? 'bg-brand-cream text-brand-teal'
                                                    : 'text-brand-gold hover:bg-brand-cream hover:text-brand-teal'
                                            }`}
                                        >
                                            {t(l.key)}
                                        </Link>
                                    ))}
                                    {/* The rule is its own element rather than a
                                        `border-t` on the button: on the button it sat
                                        inside the rounded hover fill, so the divider
                                        appeared to belong to the row instead of
                                        separating it. */}
                                    <div className="border-brand-gold/15 my-1 border-t" />
                                    <button
                                        type="button"
                                        onClick={() => router.post('/logout')}
                                        className="text-brand-teal hover:bg-brand-cream flex w-full items-center gap-2 rounded-lg px-3 py-2 text-start text-sm font-medium transition-colors"
                                    >
                                        <LogOutIcon />
                                        {t('common.logout')}
                                    </button>
                                </div>
                            </div>
                        ) : (
                            <Link
                                href={accountHref}
                                aria-label={t('nav.signIn')}
                                className="text-brand-gold hover:text-brand-teal hidden transition-colors md:inline-flex"
                            >
                                <User className="size-5" />
                            </Link>
                        )}
                        <Link
                            href="/cart"
                            aria-label={t('common.cart')}
                            className="text-brand-gold hover:text-brand-teal relative hidden transition-colors md:inline-flex"
                        >
                            <ShoppingBag className="size-5" />
                            {cartCount > 0 && (
                                <span className="bg-brand-teal absolute -end-2 -top-2 flex size-4 items-center justify-center rounded-full text-[10px] font-bold text-white">
                                    {cartCount}
                                </span>
                            )}
                        </Link>
                    </div>

                    {/* Center: logo */}
                    <Link href="/" className="justify-self-center" aria-label={t('brand')}>
                        <img src="/images/brand/logo.png" alt={t('brand')} className="h-12 w-auto" />
                    </Link>

                    {/* End: language toggle (desktop) / account + cart (mobile) */}
                    <div className="flex items-center gap-2.5 justify-self-end sm:gap-3">
                        {/* Mobile account. A plain link, NOT the desktop dropdown: a
                            hover menu is unusable on touch, and the drawer already
                            carries the full account list.

                            🔑 Signed-IN only. Signed out it duplicated the new sign-up
                            pill's job from the opposite end of a 69px bar, and the two
                            together did not fit: six controls plus a centred logo
                            overran the row and overlapped the logo. Sign-in has not
                            been lost — it is the first item in the drawer, one tap
                            from the burger the pill now sits next to, and /register
                            itself links to it. */}
                        {loggedIn && (
                            <Link
                                href={accountHref}
                                aria-label={t('common.myAccount')}
                                className="text-brand-gold hover:text-brand-teal transition-colors md:hidden"
                            >
                                <User className="size-6" />
                            </Link>
                        )}
                        <Link
                            href="/cart"
                            aria-label={t('common.cart')}
                            className="text-brand-gold hover:text-brand-teal relative transition-colors md:hidden"
                        >
                            <ShoppingBag className="size-6" />
                            {cartCount > 0 && (
                                <span className="bg-brand-teal absolute -end-2 -top-2 flex size-4 items-center justify-center rounded-full text-[10px] font-bold text-white">
                                    {cartCount}
                                </span>
                            )}
                        </Link>
                        {/* Sign-up is a filled pill rather than another icon: it was
                            previously unreachable from the storefront entirely (the
                            header only ever offered WhatsApp sign-IN), so it needs to
                            read as an invitation, not a utility. Desktop only — the
                            mobile equivalent lives in the drawer. */}
                        {!loggedIn && (
                            <Link
                                href="/register"
                                data-testid="nav-signup"
                                className="bg-brand-teal hidden rounded-full px-4 py-1.5 text-sm font-bold text-white transition-colors hover:bg-[#163e42] md:inline-flex"
                            >
                                {t('nav.signUp')}
                            </Link>
                        )}
                        {/* Both labels name the language you switch TO. The short one
                            below `md` keeps three controls on a phone row without
                            crowding the logo — "العربية"/"English" is roughly three
                            times the width of "AR"/"EN". Rendered as two spans rather
                            than a breakpoint hook so it stays a pure CSS switch (and
                            SSR emits both, so there is nothing to hydrate wrong). */}
                        <button
                            type="button"
                            data-testid="lang-toggle"
                            onClick={toggleLanguage}
                            aria-label={t('common.switchLanguage')}
                            className="border-brand-gold/40 text-brand-gold hover:bg-brand-gold/10 rounded-full border px-3 py-1 text-sm transition-colors"
                        >
                            <span className="md:hidden">{t('common.switchLanguageShort')}</span>
                            <span className="hidden md:inline">{t('common.switchLanguage')}</span>
                        </button>
                    </div>
                </div>

                {/* Row 2 — primary nav links (desktop). Padding fixed, as above. */}
                <nav className="border-brand-gold/10 hidden items-center justify-between border-t py-1.5 md:flex">
                    <Link href="/" className={`${linkBase} ${isActive('/') ? linkActive : linkIdle}`}>
                        {t('nav.home')}
                    </Link>

                    {navCategories.map((cat) =>
                        cat.children.length > 0 ? (
                            <div key={cat.id} className="group relative">
                                {/* The parent label goes to the catalogue with NO
                                    category param, which is what the "All" chip there
                                    selects. It used to point at `/` — so clicking a
                                    top-level nav item silently reloaded the homepage,
                                    which reads as a dead control.

                                    ⚠️ Deliberately NOT `?category=<parent-slug>`: the
                                    products live on the CHILD categories, so filtering
                                    by the parent would land the customer on an empty
                                    catalogue. The children are one hover away in this
                                    same dropdown.

                                    ⚠️ And deliberately no active state: every parent
                                    here resolves to the same `/shop`, so highlighting
                                    would light up all of them at once. */}
                                <Link href="/shop" className={`${linkBase} ${linkIdle} inline-flex items-center gap-1.5`}>
                                    {localized(cat, 'name')}
                                    <Caret />
                                </Link>
                                <div className="border-brand-gold/15 invisible absolute start-0 top-full z-20 min-w-48 -translate-y-1 rounded-xl border bg-white p-2 opacity-0 shadow-lg transition-all group-focus-within:visible group-focus-within:translate-y-0 group-focus-within:opacity-100 group-hover:visible group-hover:translate-y-0 group-hover:opacity-100">
                                    {cat.children.map((child) => (
                                        <Link
                                            key={child.id}
                                            href={`/shop?category=${child.slug}`}
                                            className={`block rounded-lg px-3 py-2 text-sm transition-colors ${
                                                isCategoryActive(child.slug)
                                                    ? 'bg-brand-cream text-brand-teal'
                                                    : 'text-brand-gold hover:bg-brand-cream hover:text-brand-teal'
                                            }`}
                                        >
                                            {localized(child, 'name')}
                                        </Link>
                                    ))}
                                </div>
                            </div>
                        ) : (
                            <Link
                                key={cat.id}
                                href={`/shop?category=${cat.slug}`}
                                className={`${linkBase} ${isCategoryActive(cat.slug) ? linkActive : linkIdle}`}
                            >
                                {localized(cat, 'name')}
                            </Link>
                        ),
                    )}

                    {/* A running campaign, named by the event itself, ahead of the
                        ordinary Offers link — it is the thing the store is currently
                        pushing. Nothing renders between events. */}
                    {navEvents.map((event) => (
                        <Link
                            key={event.id}
                            href={`/shop?event=${event.id}`}
                            className={`${linkBase} ${url.includes(`event=${event.id}`) ? linkActive : linkIdle}`}
                        >
                            {localized(event, 'name')}
                        </Link>
                    ))}

                    {hasOffers && (
                        <Link href="/shop?on_sale=1" className={`${linkBase} ${url.includes('on_sale=1') ? linkActive : linkIdle}`}>
                            {t('nav.offers')}
                        </Link>
                    )}
                    <Link href="/pages/about" className={`${linkBase} ${isActive('/pages/about') ? linkActive : linkIdle}`}>
                        {t('nav.about')}
                    </Link>
                    <Link href="/pages/contact" className={`${linkBase} ${isActive('/pages/contact') ? linkActive : linkIdle}`}>
                        {t('nav.contact')}
                    </Link>
                </nav>
            </div>

            {/* Mobile drawer.
                🔴 PORTALLED TO <body> ON PURPOSE — it cannot live inside <header>.
                The scroll handler above writes `header.style.transform` on every
                tick, and a transformed ancestor becomes the CONTAINING BLOCK for
                `position: fixed` descendants — even for an identity transform. So
                once the visitor had scrolled once, `fixed inset-0` resolved against
                the header's own 68px-tall box instead of the viewport, and the
                drawer rendered as a stub strip across the top with the backdrop
                dimming only the header. Measured: overlay 390×844 before any
                scroll, 390×68 after (transform "none" → "matrix(1,0,0,1,0,0)").
                It looked intermittent because a freshly loaded, unscrolled page
                was fine.
                Safe under SSR: `mobileOpen` starts false, so the server renderer
                never reaches createPortal (which it cannot render). */}
            {mobileOpen &&
                createPortal(
                    <div className="fixed inset-0 z-50 md:hidden">
                        <div className="absolute inset-0 bg-black/40" onClick={() => setMobileOpen(false)} />
                        <div className="absolute inset-y-0 start-0 flex w-72 max-w-[80%] flex-col gap-1 overflow-y-auto bg-white p-4 shadow-xl">
                            <div className="mb-2 flex items-center justify-between">
                                <img src="/images/brand/logo.png" alt={t('brand')} className="h-10 w-auto" />
                                <button
                                    type="button"
                                    onClick={() => setMobileOpen(false)}
                                    aria-label={t('nav.closeMenu')}
                                    className="text-brand-gold hover:text-brand-teal"
                                >
                                    <X className="size-6" />
                                </button>
                            </div>

                            {/* A second way into the same overlay, kept deliberately
                                even though row 1 now carries the icon on phones too:
                                someone who opened the drawer to browse categories
                                should not have to close it to search. Dressed as a
                                field rather than an icon, because in a list of links a
                                bare icon reads as another menu item. */}
                            <button
                                type="button"
                                data-testid="nav-search-mobile"
                                onClick={() => {
                                    setMobileOpen(false);
                                    setSearchOpen(true);
                                }}
                                className="border-brand-gold/30 text-brand-teal/50 hover:border-brand-gold mb-2 flex w-full items-center gap-2 rounded-full border px-3 py-2 text-start text-sm transition-colors"
                            >
                                <Search className="text-brand-gold size-4 shrink-0" />
                                {t('catalogue.searchPlaceholder')}
                            </button>

                            <Link href="/" className="text-brand-gold hover:bg-brand-cream rounded-lg px-3 py-2" onClick={() => setMobileOpen(false)}>
                                {t('nav.home')}
                            </Link>

                            {navCategories.map((cat) => (
                                <div key={cat.id} className="py-1">
                                    <p className="text-brand-teal px-3 py-1 text-xs font-semibold tracking-wide uppercase">
                                        {localized(cat, 'name')}
                                    </p>
                                    {cat.children.length > 0 ? (
                                        cat.children.map((child) => (
                                            <Link
                                                key={child.id}
                                                href={`/shop?category=${child.slug}`}
                                                className="text-brand-gold hover:bg-brand-cream hover:text-brand-teal block rounded-lg px-5 py-2 text-sm"
                                                onClick={() => setMobileOpen(false)}
                                            >
                                                {localized(child, 'name')}
                                            </Link>
                                        ))
                                    ) : (
                                        <Link
                                            href={`/shop?category=${cat.slug}`}
                                            className="text-brand-gold hover:bg-brand-cream block rounded-lg px-5 py-2 text-sm"
                                            onClick={() => setMobileOpen(false)}
                                        >
                                            {localized(cat, 'name')}
                                        </Link>
                                    )}
                                </div>
                            ))}

                            {navEvents.map((event) => (
                                <Link
                                    key={event.id}
                                    href={`/shop?event=${event.id}`}
                                    className="text-brand-gold hover:bg-brand-cream rounded-lg px-3 py-2"
                                    onClick={() => setMobileOpen(false)}
                                >
                                    {localized(event, 'name')}
                                </Link>
                            ))}

                            {hasOffers && (
                                <Link
                                    href="/shop?on_sale=1"
                                    className="text-brand-gold hover:bg-brand-cream rounded-lg px-3 py-2"
                                    onClick={() => setMobileOpen(false)}
                                >
                                    {t('nav.offers')}
                                </Link>
                            )}
                            <Link
                                href="/pages/about"
                                className="text-brand-gold hover:bg-brand-cream rounded-lg px-3 py-2"
                                onClick={() => setMobileOpen(false)}
                            >
                                {t('nav.about')}
                            </Link>
                            <Link
                                href="/pages/contact"
                                className="text-brand-gold hover:bg-brand-cream rounded-lg px-3 py-2"
                                onClick={() => setMobileOpen(false)}
                            >
                                {t('nav.contact')}
                            </Link>

                            {/* Account block. Signed out, this is the ONLY sign-up entry
                                point on a phone (the header pill is desktop-only), so it
                                is a filled button rather than another quiet link. */}
                            <div className="border-brand-gold/15 mt-3 space-y-1 border-t pt-3">
                                {loggedIn ? (
                                    <>
                                        {ACCOUNT_LINKS.map((l) => (
                                            <Link
                                                key={l.href}
                                                href={l.href}
                                                className="text-brand-gold hover:bg-brand-cream block rounded-lg px-3 py-2 text-sm"
                                                onClick={() => setMobileOpen(false)}
                                            >
                                                {t(l.key)}
                                            </Link>
                                        ))}
                                        {/* Outlined rather than a red text row, so it
                                            reads as an ACTION and not as a fourth
                                            account destination that happens to be red
                                            — which is how it looked sitting flush under
                                            المفضلة. It deliberately takes the same
                                            shape as the sign-up button a signed-OUT
                                            visitor sees in this exact spot, so the
                                            drawer always ends in one button; the two
                                            states never appear together, so there is
                                            nothing to compare it against.

                                            `py-2.5` not `py-2`: this is the only
                                            destructive control on a touch surface, and
                                            36px was under the comfortable tap size.

                                            Gold rule, cream fill, teal label — the
                                            drawer's own palette, with the warning
                                            colour confined to the glyph (see
                                            LogOutIcon). */}
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setMobileOpen(false);
                                                router.post('/logout');
                                            }}
                                            className="border-brand-gold/40 bg-brand-cream/60 text-brand-teal hover:border-brand-gold/70 hover:bg-brand-cream active:bg-brand-cream mt-2 flex w-full items-center justify-center gap-2 rounded-lg border px-3 py-2.5 text-sm font-bold transition-colors"
                                        >
                                            <LogOutIcon />
                                            {t('common.logout')}
                                        </button>
                                    </>
                                ) : (
                                    <>
                                        {/* Same resolved destination as the header
                                            icon — never a second hardcoded path that
                                            could disagree with it. */}
                                        <Link
                                            href={accountHref}
                                            className="text-brand-gold hover:bg-brand-cream block rounded-lg px-3 py-2 text-sm"
                                            onClick={() => setMobileOpen(false)}
                                        >
                                            {t('nav.signIn')}
                                        </Link>
                                        <Link
                                            href="/register"
                                            className="bg-brand-teal block rounded-lg px-3 py-2 text-center text-sm font-bold text-white"
                                            onClick={() => setMobileOpen(false)}
                                        >
                                            {t('nav.signUp')}
                                        </Link>
                                    </>
                                )}
                                {/* No language toggle here on purpose. Row 1 of the
                                    header carries one at every width (it shortens to
                                    EN/AR below `md`), so a second copy inside the
                                    drawer was a duplicate of a control already on
                                    screen behind it. */}
                            </div>
                        </div>
                    </div>,
                    document.body,
                )}

            {/* Portals to <body> itself — see the component. It must not be a fixed
                descendant of this transformed header. */}
            <SearchOverlay open={searchOpen} onClose={() => setSearchOpen(false)} />
        </header>
    );
}

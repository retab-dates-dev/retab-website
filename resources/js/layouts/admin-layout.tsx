import AdminToasts from '@/components/admin/admin-toasts';
import ChangePasswordForm from '@/components/admin/change-password-form';
import AdminContextMenu from '@/components/admin/context-menu';
import GlobalSearch from '@/components/admin/global-search';
import MobileScrollNav from '@/components/admin/mobile-scroll-nav';
import Modal from '@/components/admin/modal';
import NotificationBell from '@/components/admin/notification-bell';
import RevertConflictBanner from '@/components/admin/revert-conflict-banner';
import UndoToast from '@/components/admin/undo-toast';
import adminI18n from '@/i18n/admin';
import { Link, router, usePage } from '@inertiajs/react';
import {
    BadgePercent,
    Boxes,
    CalendarHeart,
    FileText,
    History,
    Info,
    KeyRound,
    Languages,
    LayoutDashboard,
    LogOut,
    Mail,
    Megaphone,
    Menu,
    MessageSquareQuote,
    Package,
    RotateCcw,
    Settings,
    ShieldCheck,
    ShoppingBag,
    Sparkles,
    Star,
    TicketPercent,
    Truck,
    Users,
    X,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useRef, useState, type PropsWithChildren, type ReactNode } from 'react';
import { I18nextProvider, useTranslation } from 'react-i18next';

type AdminLocale = 'en' | 'ar';
const STORAGE_KEY = 'retab_admin_locale';
const SIDEBAR_KEY = 'retab_admin_sidebar_collapsed';
// Per-session flag: set once the help drawer is opened, so the attention beam
// stops for the rest of the session and resumes next session (sessionStorage).
const HELP_SEEN_KEY = 'retab_admin_help_seen';

// `perm` = the permission SECTION an editor needs "<perm>.view" to see the item.
// No `perm` = always visible to staff (dashboard). `adminOnly` = admins only.
type NavItem = { key: string; href: string; icon: LucideIcon; perm?: string; adminOnly?: boolean };

// Per-page "How it works" help, authored in i18n under `admin.help.pages.<navKey>`.
type HelpContent = { intro?: string; steps?: string[]; rules?: string[] };

// The dashboard sits alone above the grouped sections.
const NAV_TOP: NavItem[] = [{ key: 'dashboard', href: '/admin/dashboard', icon: LayoutDashboard }];

// Related sections grouped under a header (label = `admin.navGroups.<key>`) so
// the sidebar scans at a glance. A group with no visible items is hidden whole.
const NAV_GROUPS: { key: string; items: NavItem[] }[] = [
    {
        key: 'sales',
        items: [
            { key: 'orders', href: '/admin/orders', icon: ShoppingBag, perm: 'orders' },
            { key: 'returns', href: '/admin/returns', icon: RotateCcw, perm: 'returns' },
            { key: 'shipping', href: '/admin/shipping', icon: Truck, perm: 'shipping' },
            { key: 'customers', href: '/admin/customers', icon: Users, perm: 'customers' },
        ],
    },
    {
        key: 'catalog',
        items: [
            { key: 'products', href: '/admin/products', icon: Package, perm: 'products' },
            { key: 'productRequests', href: '/admin/product-requests', icon: Sparkles, perm: 'product_requests' },
            { key: 'productReviews', href: '/admin/product-reviews', icon: MessageSquareQuote, perm: 'product_reviews' },
            { key: 'inventory', href: '/admin/stock-import', icon: Boxes, perm: 'inventory' },
        ],
    },
    {
        key: 'promotions',
        items: [
            { key: 'marketing', href: '/admin/marketing', icon: Megaphone, perm: 'marketing' },
            { key: 'coupons', href: '/admin/coupons', icon: TicketPercent, perm: 'coupons' },
            { key: 'discounts', href: '/admin/discounts', icon: BadgePercent, perm: 'discounts' },
            { key: 'storeEvents', href: '/admin/store-events', icon: CalendarHeart, perm: 'store_events' },
        ],
    },
    {
        key: 'content',
        items: [
            { key: 'reviews', href: '/admin/client-reviews', icon: Star, perm: 'reviews' },
            { key: 'contentPages', href: '/admin/content-pages', icon: FileText, perm: 'content_pages' },
            { key: 'contactMessages', href: '/admin/contact-messages', icon: Mail, perm: 'contact_messages' },
        ],
    },
];

// Pinned to the bottom of the sidebar, in this order (top → bottom).
const NAV_BOTTOM: NavItem[] = [
    { key: 'changeLog', href: '/admin/change-log', icon: History, perm: 'change_log' },
    // Not adminOnly any more: an editor granted `staff.view` can read the
    // directory so they can reset a colleague's password. Every write on that
    // page is still admin-gated server-side (routes/admin.php).
    { key: 'users', href: '/admin/users', icon: ShieldCheck, perm: 'staff' },
    { key: 'settings', href: '/admin/settings', icon: Settings, perm: 'settings' },
];

// Flat list for the active-section + per-page-help lookups.
const ALL_NAV: NavItem[] = [...NAV_TOP, ...NAV_GROUPS.flatMap((g) => g.items), ...NAV_BOTTOM];

// Muted section header above each nav group.
// On the teal sidebar, neutral-500 sits below a readable contrast — this is the
// muted tone that still reads against #133c40.
const GROUP_LABEL = 'px-3 pb-1.5 pt-1 text-[11px] font-semibold uppercase tracking-wider text-[#7f9fa1]';

function AdminShell({ children, title }: PropsWithChildren<{ title?: ReactNode }>) {
    const { t, i18n } = useTranslation();
    const page = usePage();
    const props = page.props as {
        auth?: {
            user?: { name?: string | null; email?: string | null; role?: string | null };
            permissions?: Record<string, Record<string, boolean>> | null;
        };
        flash?: { success?: string | null; error?: string | null };
        helpPulse?: boolean | null;
    };
    const user = props.auth?.user;
    const currentPath = page.url.split('?')[0];
    // The scrollable content area, targeted by the mobile scroll-to-edge buttons.
    const mainRef = useRef<HTMLElement>(null);

    // Editors see only the sections they can view; admins (permissions === null) see all.
    const isAdmin = user?.role === 'admin';
    const permissions = props.auth?.permissions ?? null;
    const canSee = (item: NavItem) => {
        if (item.adminOnly) return isAdmin;
        if (isAdmin || !item.perm) return true;
        return Boolean(permissions?.[item.perm]?.view);
    };
    const navTop = NAV_TOP.filter(canSee);
    const navGroups = NAV_GROUPS.map((group) => ({ key: group.key, items: group.items.filter(canSee) })).filter((group) => group.items.length > 0);
    const navBottom = NAV_BOTTOM.filter(canSee);

    // The current section's sidebar icon, to prefix the header title (string
    // titles only — pages that pass a styled node, e.g. Marketing, keep their own).
    const activeNavItem = ALL_NAV.find(
        (item) => currentPath === item.href || (item.href !== '/admin/dashboard' && currentPath.startsWith(item.href)),
    );
    const TitleIcon = activeNavItem?.icon;

    // Per-page help drawer, keyed off the active section so every page gets one
    // for free (content lives in i18n under `admin.help.pages.<navKey>`).
    const helpKey = activeNavItem ? `admin.help.pages.${activeNavItem.key}` : null;
    const helpRaw = helpKey ? (t(helpKey, { returnObjects: true, defaultValue: null }) as unknown) : null;
    const help = helpRaw && typeof helpRaw === 'object' ? (helpRaw as HelpContent) : null;
    const [helpOpen, setHelpOpen] = useState(false);

    // Attention beam: pulses the help button until opened this session, unless the
    // global setting turns it off. Only those who can reach Settings get the
    // "turn it off" link in the drawer.
    const helpPulseEnabled = props.helpPulse ?? true;
    const [helpSeen, setHelpSeen] = useState(false);
    const canManageSettings = isAdmin || Boolean(permissions?.settings?.view);
    const openHelp = () => {
        setHelpOpen(true);
        setHelpSeen(true);
        if (typeof window !== 'undefined') sessionStorage.setItem(HELP_SEEN_KEY, '1');
    };

    const renderNavItem = (item: NavItem) => {
        const active = currentPath === item.href || (item.href !== '/admin/dashboard' && currentPath.startsWith(item.href));
        const Icon = item.icon;
        return (
            <Link
                key={item.key}
                href={item.href}
                className={
                    active
                        ? 'text-brand-gold flex items-center gap-3 rounded-lg bg-[#1b4e53] px-3 py-2 text-sm font-semibold'
                        : 'flex items-center gap-3 rounded-lg px-3 py-2 text-sm text-[#a8c2c4] transition-colors hover:bg-[#1b4e53]/60 hover:text-[#f5f1ea]'
                }
            >
                <Icon className="h-5 w-5 shrink-0" />
                <span>{t(`admin.nav.${item.key}`)}</span>
            </Link>
        );
    };

    // Admin language: English by default, persisted in localStorage. RTL scoped
    // to this subtree via the root `dir` attribute (document.dir stays the
    // storefront's — we never touch it here).
    const [locale, setLocale] = useState<AdminLocale>('en');
    const isRTL = locale === 'ar';

    useEffect(() => {
        const saved = typeof window !== 'undefined' ? (localStorage.getItem(STORAGE_KEY) as AdminLocale | null) : null;
        if (saved === 'ar' || saved === 'en') setLocale(saved);
    }, []);

    useEffect(() => {
        i18n.changeLanguage(locale);
        // Mirror the admin's language to a cookie so the server localizes admin
        // flash + validation messages in it (SetAdminLocale middleware), instead
        // of the storefront session locale (which defaults to Arabic).
        if (typeof document !== 'undefined') {
            document.cookie = `admin_locale=${locale}; path=/; max-age=31536000; samesite=lax`;
        }
    }, [locale, i18n]);

    const toggleLocale = () => {
        const next: AdminLocale = locale === 'en' ? 'ar' : 'en';
        setLocale(next);
        if (typeof window !== 'undefined') localStorage.setItem(STORAGE_KEY, next);
    };

    // Sidebar: a mobile drawer (sidebarOpen) below lg, and a persisted collapse
    // on desktop (collapsed). The one header button adapts to the breakpoint.
    // `mounted` gates the slide transition so it never fires on first paint /
    // Inertia remount (only on an actual user toggle).
    // Own-account password change. Lives in the top bar because EDITORS cannot
    // reach /admin/users (it is admin-only), so the Staff page alone would leave
    // them with no way to change their own password.
    const [accountOpen, setAccountOpen] = useState(false);
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [collapsed, setCollapsed] = useState(false);
    const [mounted, setMounted] = useState(false);
    useEffect(() => {
        setCollapsed(localStorage.getItem(SIDEBAR_KEY) === '1');
        if (sessionStorage.getItem(HELP_SEEN_KEY) === '1') setHelpSeen(true);
        const id = requestAnimationFrame(() => setMounted(true));
        return () => cancelAnimationFrame(id);
    }, []);
    useEffect(() => router.on('navigate', () => setSidebarOpen(false)), []);
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                setSidebarOpen(false);
                setHelpOpen(false);
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    const toggleSidebar = () => {
        if (window.matchMedia('(min-width: 1024px)').matches) {
            setCollapsed((c) => {
                const next = !c;
                localStorage.setItem(SIDEBAR_KEY, next ? '1' : '0');
                return next;
            });
        } else {
            setSidebarOpen(true);
        }
    };

    return (
        <div
            dir={isRTL ? 'rtl' : 'ltr'}
            lang={locale}
            className="admin-shell dark flex h-dvh overflow-hidden bg-neutral-950 font-sans text-neutral-100"
        >
            {/* Backdrop (mobile only, when the drawer is open) */}
            {sidebarOpen && <div className="fixed inset-0 z-30 bg-black/50 lg:hidden" onClick={() => setSidebarOpen(false)} />}

            {/* Sidebar — a fixed off-canvas drawer on mobile, static column on desktop.
                Mobile toggles `transform`; desktop collapses `width` (both smoothly).
                The inner w-60 wrapper keeps its layout while the shell width animates,
                so nothing reflows mid-slide. Direction handled in JS (Tailwind dropped
                the max-lg:rtl: triple-stack). */}
            <aside
                className={`fixed inset-y-0 ${isRTL ? 'right-0' : 'left-0'} z-40 flex w-60 shrink-0 flex-col overflow-hidden border-e border-[#1b4e53] bg-[#133c40] ease-in-out lg:static lg:translate-x-0 ${
                    mounted ? 'transition-all duration-300' : ''
                } ${collapsed ? 'lg:w-0 lg:border-e-0' : 'lg:w-60'} ${
                    sidebarOpen ? 'translate-x-0' : isRTL ? 'translate-x-full' : '-translate-x-full'
                }`}
            >
                <div className="flex h-full w-60 shrink-0 flex-col">
                    <div className="flex h-16 shrink-0 items-center justify-between gap-2 border-b border-[#1b4e53] px-5">
                        <Link href="/admin/dashboard" className="text-brand-gold flex min-w-0 items-center gap-2 text-lg font-bold">
                            <ShieldCheck className="h-5 w-5 shrink-0" />
                            <span className="truncate">{t('admin.brand')}</span>
                        </Link>
                        <div className="flex shrink-0 items-center gap-2">
                            <img src="/images/brand/logo.png" alt="Retab" className="h-9 w-auto" />
                            <button
                                type="button"
                                onClick={() => setSidebarOpen(false)}
                                aria-label={t('admin.common.closeMenu')}
                                className="text-neutral-400 hover:text-white lg:hidden"
                            >
                                <X className="h-5 w-5" />
                            </button>
                        </div>
                    </div>
                    <nav className="flex flex-1 flex-col overflow-y-auto p-3">
                        <div className="space-y-1">{navTop.map(renderNavItem)}</div>
                        {navGroups.map((group) => (
                            <div key={group.key} className="mt-4">
                                <p className={GROUP_LABEL}>{t(`admin.navGroups.${group.key}`)}</p>
                                <div className="space-y-1">{group.items.map(renderNavItem)}</div>
                            </div>
                        ))}
                        {navBottom.length > 0 && (
                            <div className="mt-auto border-t border-[#1b4e53] pt-3">
                                <p className={GROUP_LABEL}>{t('admin.navGroups.system')}</p>
                                <div className="space-y-1">{navBottom.map(renderNavItem)}</div>
                            </div>
                        )}
                    </nav>
                </div>
            </aside>

            {/* Main column */}
            <div className="flex min-w-0 flex-1 flex-col">
                <header className="flex h-16 shrink-0 items-center gap-3 border-b border-neutral-800 bg-neutral-900 px-4 sm:px-6">
                    <button
                        type="button"
                        onClick={toggleSidebar}
                        aria-label={t('admin.common.toggleSidebar')}
                        className="shrink-0 rounded-lg p-1.5 text-neutral-300 transition-colors hover:bg-neutral-800 hover:text-white"
                    >
                        <Menu className="h-5 w-5" />
                    </button>
                    <h1 className="hidden shrink-0 items-center gap-2 truncate text-lg font-semibold lg:flex">
                        {typeof title === 'string' ? (
                            <>
                                {TitleIcon && <TitleIcon className="text-brand-gold h-5 w-5 shrink-0" />}
                                <span className="truncate">{title}</span>
                            </>
                        ) : (
                            title
                        )}
                    </h1>
                    {help && (
                        <button
                            type="button"
                            onClick={openHelp}
                            className={`hover:border-brand-gold hover:text-brand-gold flex shrink-0 items-center gap-1.5 rounded-lg border border-neutral-700 px-2.5 py-1.5 text-xs font-medium text-neutral-300 transition-colors ${
                                helpPulseEnabled && mounted && !helpSeen && !helpOpen ? 'help-beam' : ''
                            }`}
                        >
                            <Info className="text-brand-gold h-4 w-4" />
                            <span className="hidden sm:inline">{t('admin.help.button')}</span>
                        </button>
                    )}
                    <div className="flex flex-1 justify-center">
                        <GlobalSearch />
                    </div>
                    <div className="flex shrink-0 items-center gap-3 text-sm">
                        <NotificationBell />
                        <button
                            type="button"
                            onClick={toggleLocale}
                            className="flex items-center gap-1.5 rounded-lg px-2 py-1.5 text-neutral-300 transition-colors hover:bg-neutral-800"
                        >
                            <Languages className="h-4 w-4" />
                            <span>{locale === 'en' ? 'العربية' : 'English'}</span>
                        </button>
                        {user && <span className="hidden text-neutral-400 sm:inline">{user.name ?? user.email}</span>}
                        {user && (
                            <button
                                type="button"
                                onClick={() => setAccountOpen(true)}
                                aria-label={t('admin.account.changePassword')}
                                title={t('admin.account.changePassword')}
                                className="flex items-center gap-1.5 rounded-lg px-2 py-1.5 text-neutral-400 transition-colors hover:bg-neutral-800 hover:text-white"
                            >
                                <KeyRound className="h-4 w-4" />
                            </button>
                        )}
                        <button
                            type="button"
                            onClick={() => router.post('/logout')}
                            className="flex items-center gap-1.5 text-neutral-400 transition-colors hover:text-white"
                        >
                            <LogOut className="h-4 w-4" />
                            <span className="hidden sm:inline">{t('admin.logout')}</span>
                        </button>
                    </div>
                </header>

                <main ref={mainRef} className="flex-1 overflow-y-auto p-4 sm:p-6">
                    <RevertConflictBanner />
                    {children}
                </main>
            </div>

            <AdminToasts />
            <UndoToast />
            <AdminContextMenu />
            <MobileScrollNav scrollRef={mainRef} />

            {/* Reachable from every admin page, so editors have a route to their
                own password even though the Staff page is admin-only. */}
            <Modal open={accountOpen} onClose={() => setAccountOpen(false)} title={t('admin.account.title')} size="md">
                <div className="px-5 py-4">
                    <ChangePasswordForm compact />
                </div>
            </Modal>

            {/* Per-page "How it works" drawer. Slides from the reading-end side
                (right in LTR, left in RTL). Physical transform, so RTL is handled
                explicitly rather than via a logical utility Tailwind may drop. */}
            {help && (
                <>
                    <div
                        className={`fixed inset-0 z-40 bg-black/50 transition-opacity duration-300 ${helpOpen ? 'opacity-100' : 'pointer-events-none opacity-0'}`}
                        onClick={() => setHelpOpen(false)}
                        aria-hidden="true"
                    />
                    <aside
                        role="dialog"
                        aria-modal="true"
                        aria-label={t('admin.help.button')}
                        style={{ transform: helpOpen ? 'translateX(0)' : isRTL ? 'translateX(-100%)' : 'translateX(100%)' }}
                        className={`fixed inset-y-0 ${isRTL ? 'left-0' : 'right-0'} z-50 flex w-full max-w-sm flex-col bg-neutral-900 shadow-2xl transition-transform duration-300`}
                    >
                        <div className="flex h-16 shrink-0 items-center justify-between border-b border-neutral-800 px-5">
                            <h2 className="flex items-center gap-2 font-bold text-neutral-100">
                                <Info className="text-brand-gold h-5 w-5" /> {t('admin.help.button')}
                            </h2>
                            <button
                                type="button"
                                onClick={() => setHelpOpen(false)}
                                aria-label={t('admin.common.close')}
                                className="rounded p-1 text-neutral-400 transition-colors hover:text-white"
                            >
                                <X className="h-5 w-5" />
                            </button>
                        </div>
                        <div className="flex-1 overflow-y-auto px-5 py-5">
                            {help.intro && <p className="mb-4 text-sm text-neutral-400">{help.intro}</p>}
                            {help.steps && help.steps.length > 0 && (
                                <ol className="space-y-3">
                                    {help.steps.map((step, i) => (
                                        <li key={i} className="flex gap-3 text-sm text-neutral-200">
                                            <span className="bg-brand-teal/25 text-brand-gold flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-bold">
                                                {i + 1}
                                            </span>
                                            <span dir="auto">{step}</span>
                                        </li>
                                    ))}
                                </ol>
                            )}
                            {help.rules && help.rules.length > 0 && (
                                <>
                                    <p className="mt-6 mb-2 text-xs font-semibold tracking-wide text-neutral-500 uppercase">
                                        {t('admin.help.rulesTitle')}
                                    </p>
                                    <ul className="space-y-2">
                                        {help.rules.map((rule, i) => (
                                            <li key={i} className="flex gap-2 text-sm text-neutral-400">
                                                <span className="text-brand-gold">•</span>
                                                <span dir="auto">{rule}</span>
                                            </li>
                                        ))}
                                    </ul>
                                </>
                            )}
                            {canManageSettings && (
                                <Link
                                    href="/admin/settings#help-pulse"
                                    onClick={() => setHelpOpen(false)}
                                    className="hover:text-brand-gold mt-6 flex items-center gap-1.5 border-t border-neutral-800 pt-4 text-xs text-neutral-400 transition-colors"
                                >
                                    <Settings className="h-3.5 w-3.5 shrink-0" /> {t('admin.help.turnOffPulse')}
                                </Link>
                            )}
                        </div>
                    </aside>
                </>
            )}
        </div>
    );
}

export default function AdminLayout(props: PropsWithChildren<{ title?: ReactNode }>) {
    return (
        <I18nextProvider i18n={adminI18n}>
            <AdminShell {...props} />
        </I18nextProvider>
    );
}

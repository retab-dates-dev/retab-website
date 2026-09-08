import { Head, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ExternalLink, ImageOff, ImageUp, Plus, Search, Trash2, X } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

import Button from '@/components/admin/button';
import StatusBadge from '@/components/admin/status-badge';
import StatusPill from '@/components/status-pill';
import { useAdminT } from '@/i18n/use-admin-t';
import AdminLayout from '@/layouts/admin-layout';
import { CARD } from '@/lib/admin-ui';
import { normalize } from '@/lib/search';

import EventFields, { type EventForm, toInput } from './event-fields';
import { type EventRow } from './index';

/**
 * One store event: its own fields, the offers it fronts, and the pool to add from.
 *
 * 🔑 Everything on the offer side lives on the PIVOT, so nothing here edits a
 * product. An event can be assembled and torn down without touching the catalogue,
 * and the same product carries a different badge in next year's campaign.
 */

interface Offer {
    product_id: number;
    name_ar: string;
    name_en: string | null;
    sku: string;
    slug: string;
    price: number;
    sale_price: number | null;
    on_sale: boolean;
    sale_state: string | null;
    sale_ends_at: string | null;
    is_active: boolean;
    badge_ar: string | null;
    badge_en: string | null;
    banner_image: string | null;
    has_banner: boolean;
    preview: string | null;
    sort_order: number;
}

interface PoolProduct {
    id: number;
    name_ar: string;
    name_en: string | null;
    sku: string;
    price: number;
    on_sale: boolean;
    image: string | null;
}

const INPUT =
    'w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm text-neutral-900 outline-none focus:border-brand-gold focus:ring-1 focus:ring-brand-gold dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-100';

/** One offer row: what the card will show, plus the two things the offer owns. */
function OfferCard({
    eventId,
    offer,
    index,
    total,
    onMove,
}: {
    eventId: number;
    offer: Offer;
    index: number;
    total: number;
    onMove: (from: number, to: number) => void;
}) {
    const { t } = useAdminT();
    const [badgeAr, setBadgeAr] = useState(offer.badge_ar ?? '');
    const [badgeEn, setBadgeEn] = useState(offer.badge_en ?? '');
    const fileRef = useRef<HTMLInputElement>(null);
    const base = `/admin/store-events/${eventId}/offers/${offer.product_id}`;
    const dirty = badgeAr !== (offer.badge_ar ?? '') || badgeEn !== (offer.badge_en ?? '');

    const upload = (file: File) => {
        // Multipart, so POST — a PATCH body is not parsed by PHP and the file would
        // arrive empty. Same reason product images have their own endpoint.
        router.post(`${base}/banner`, { banner: file }, { forceFormData: true, preserveScroll: true });
    };

    return (
        <div className={`${CARD} flex flex-col gap-3 p-3 sm:flex-row sm:items-start`}>
            <div className="relative aspect-[16/9] w-full shrink-0 overflow-hidden rounded-lg bg-neutral-100 sm:w-56 dark:bg-neutral-800">
                {offer.preview ? (
                    <img src={offer.preview} alt="" className="h-full w-full object-cover" />
                ) : (
                    <div className="flex h-full w-full items-center justify-center text-3xl">🌴</div>
                )}
                <span className="absolute start-1.5 top-1.5 rounded-full bg-black/60 px-2 py-0.5 text-[10px] font-medium text-white">
                    {offer.has_banner ? t('admin.storeEvents.offer.artwork') : t('admin.storeEvents.offer.productPhoto')}
                </span>
            </div>

            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="font-medium text-neutral-900 dark:text-neutral-100">{offer.name_ar}</span>
                    <span className="font-mono text-[11px] text-neutral-500">{offer.sku}</span>
                    {!offer.is_active && <StatusPill tone="stopped">{t('admin.storeEvents.offer.hidden')}</StatusPill>}
                </div>

                <div className="mt-1 flex flex-wrap items-center gap-2 text-sm text-neutral-600 tabular-nums dark:text-neutral-300">
                    <span className={offer.on_sale ? 'font-semibold' : ''}>{(offer.sale_price ?? offer.price).toFixed(2)}</span>
                    {offer.on_sale && <span className="text-xs text-neutral-400 line-through">{offer.price.toFixed(2)}</span>}
                    {/* 🔑 The commonest way an event looks wrong on the storefront is a
                        discount that is only scheduled, or already lapsed. Surfaced
                        here so it is visible while arranging the campaign rather than
                        after someone reports the price. */}
                    {offer.sale_state && <StatusBadge domain="discount" value={offer.sale_state} />}
                    {!offer.sale_price && <span className="text-xs text-neutral-500">{t('admin.storeEvents.offer.noDiscount')}</span>}
                </div>

                <div className="mt-3 grid gap-2 sm:grid-cols-2">
                    <input
                        className={INPUT}
                        dir="rtl"
                        value={badgeAr}
                        onChange={(e) => setBadgeAr(e.target.value)}
                        placeholder={t('admin.storeEvents.offer.badgeArPlaceholder')}
                        aria-label={t('admin.storeEvents.offer.badgeAr')}
                    />
                    <input
                        className={INPUT}
                        dir="ltr"
                        value={badgeEn}
                        onChange={(e) => setBadgeEn(e.target.value)}
                        placeholder={t('admin.storeEvents.offer.badgeEnPlaceholder')}
                        aria-label={t('admin.storeEvents.offer.badgeEn')}
                    />
                </div>
                <p className="mt-1 text-[11px] text-neutral-500">{t('admin.storeEvents.offer.badgeHint')}</p>

                <div className="mt-3 flex flex-wrap items-center gap-2">
                    <Button
                        size="sm"
                        variant="secondary"
                        disabled={!dirty}
                        onClick={() => router.patch(base, { badge_ar: badgeAr || null, badge_en: badgeEn || null }, { preserveScroll: true })}
                    >
                        {t('admin.storeEvents.offer.save')}
                    </Button>

                    <input
                        ref={fileRef}
                        type="file"
                        accept="image/*"
                        className="hidden"
                        aria-label={t('admin.storeEvents.offer.uploadArtwork')}
                        onChange={(e) => {
                            const file = e.target.files?.[0];
                            if (file) upload(file);
                            // Cleared so re-picking the SAME file fires onChange again.
                            e.target.value = '';
                        }}
                    />
                    <Button size="sm" variant="secondary" icon={ImageUp} onClick={() => fileRef.current?.click()}>
                        {t('admin.storeEvents.offer.uploadArtwork')}
                    </Button>
                    {offer.has_banner && (
                        <Button size="sm" variant="ghost" icon={ImageOff} onClick={() => router.delete(`${base}/banner`, { preserveScroll: true })}>
                            {t('admin.storeEvents.offer.removeArtwork')}
                        </Button>
                    )}
                    <Button size="sm" variant="ghost" icon={ExternalLink} href={`/products/${offer.slug}`}>
                        {t('admin.storeEvents.offer.viewOnStore')}
                    </Button>
                </div>
            </div>

            <div className="flex shrink-0 flex-row gap-1 sm:flex-col">
                {/* Up/down rather than drag: keyboard-reachable, and it needs no
                    pointer library for a list that is rarely more than a handful. */}
                <Button
                    size="sm"
                    variant="ghost"
                    icon={ArrowUp}
                    disabled={index === 0}
                    onClick={() => onMove(index, index - 1)}
                    aria-label={t('admin.storeEvents.offer.moveUp')}
                >
                    {''}
                </Button>
                <Button
                    size="sm"
                    variant="ghost"
                    icon={ArrowDown}
                    disabled={index === total - 1}
                    onClick={() => onMove(index, index + 1)}
                    aria-label={t('admin.storeEvents.offer.moveDown')}
                >
                    {''}
                </Button>
                <Button
                    size="sm"
                    variant="danger"
                    icon={Trash2}
                    onClick={() => router.delete(base, { preserveScroll: true })}
                    aria-label={t('admin.storeEvents.offer.remove')}
                >
                    {''}
                </Button>
            </div>
        </div>
    );
}

export default function StoreEventShow({
    event,
    pool,
    accentPresets,
}: {
    event: EventRow & { offers: Offer[] };
    pool: PoolProduct[];
    accentPresets: Record<string, string>;
}) {
    const { t } = useAdminT();
    const [form, setForm] = useState<EventForm>({
        name_ar: event.name_ar,
        name_en: event.name_en ?? '',
        subtitle_ar: event.subtitle_ar ?? '',
        subtitle_en: event.subtitle_en ?? '',
        starts_at: toInput(event.starts_at),
        ends_at: toInput(event.ends_at),
        accent_color: event.accent_color ?? '',
        is_active: event.is_active,
        sort_order: event.sort_order,
    });
    const [query, setQuery] = useState('');

    // Arabic-folded matching, reusing the storefront search's normalizer so «جده»
    // finds «جدة» here too. One normalizer, not a second copy that drifts.
    const matches = useMemo(() => {
        const q = normalize(query);
        if (!q) return pool.slice(0, 8);
        return pool.filter((p) => normalize(`${p.name_ar} ${p.name_en ?? ''} ${p.sku}`).includes(q)).slice(0, 8);
    }, [pool, query]);

    const move = (from: number, to: number) => {
        const ids = event.offers.map((o) => o.product_id);
        const [moved] = ids.splice(from, 1);
        ids.splice(to, 0, moved);
        router.post(`/admin/store-events/${event.id}/offers/reorder`, { product_ids: ids }, { preserveScroll: true });
    };

    return (
        <AdminLayout>
            <Head title={event.name_ar} />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                    <span className="h-9 w-1.5 rounded-full" style={{ background: event.accent }} aria-hidden />
                    <div>
                        <h1 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{event.name_ar}</h1>
                        <div className="mt-1 flex items-center gap-2">
                            <StatusBadge domain="storeEvent" value={event.state} />
                            <span className="text-xs text-neutral-500">{t('admin.storeEvents.offerCount', { n: event.offers.length })}</span>
                        </div>
                    </div>
                </div>
                <Button variant="secondary" href="/admin/store-events">
                    {t('admin.storeEvents.backToList')}
                </Button>
            </div>

            <section className={`${CARD} mb-6 p-5`}>
                <h2 className="mb-4 text-sm font-semibold text-neutral-900 dark:text-neutral-100">{t('admin.storeEvents.details')}</h2>
                <EventFields form={form} onChange={setForm} accentPresets={accentPresets} />
                <div className="mt-5 flex justify-end">
                    <Button onClick={() => router.put(`/admin/store-events/${event.id}`, { ...form }, { preserveScroll: true })}>
                        {t('admin.storeEvents.saveEvent')}
                    </Button>
                </div>
            </section>

            <section className={`${CARD} mb-6 p-5`}>
                <h2 className="mb-1 text-sm font-semibold text-neutral-900 dark:text-neutral-100">{t('admin.storeEvents.addOffer')}</h2>
                <p className="mb-3 text-xs text-neutral-500">{t('admin.storeEvents.addOfferHint')}</p>

                <div className="relative">
                    <Search className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                    <input
                        className={`${INPUT} ps-9`}
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder={t('admin.storeEvents.searchProducts')}
                        aria-label={t('admin.storeEvents.searchProducts')}
                    />
                    {query && (
                        <button
                            type="button"
                            onClick={() => setQuery('')}
                            aria-label={t('admin.common.clear')}
                            className="absolute end-2 top-1/2 -translate-y-1/2 rounded p-1 text-neutral-400 hover:text-neutral-700"
                        >
                            <X className="h-4 w-4" />
                        </button>
                    )}
                </div>

                <div className="mt-3 grid gap-2 sm:grid-cols-2">
                    {matches.map((p) => (
                        <button
                            key={p.id}
                            type="button"
                            onClick={() =>
                                router.post(
                                    `/admin/store-events/${event.id}/offers`,
                                    { product_id: p.id },
                                    { preserveScroll: true, onSuccess: () => setQuery('') },
                                )
                            }
                            className="flex items-center gap-3 rounded-lg border border-neutral-200 p-2 text-start transition hover:border-neutral-400 hover:bg-neutral-50 dark:border-neutral-800 dark:hover:bg-neutral-800/50"
                        >
                            {p.image ? (
                                <img src={p.image} alt="" className="h-10 w-10 shrink-0 rounded object-cover" />
                            ) : (
                                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded bg-neutral-100 dark:bg-neutral-800">
                                    🌴
                                </span>
                            )}
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-sm text-neutral-900 dark:text-neutral-100">{p.name_ar}</span>
                                <span className="block font-mono text-[11px] text-neutral-500">
                                    {p.sku} · {p.price.toFixed(2)}
                                </span>
                            </span>
                            <Plus className="h-4 w-4 shrink-0 text-neutral-400" />
                        </button>
                    ))}
                    {matches.length === 0 && (
                        <p className="col-span-full py-4 text-center text-sm text-neutral-500">{t('admin.storeEvents.noProducts')}</p>
                    )}
                </div>
            </section>

            <h2 className="mb-3 text-sm font-semibold text-neutral-900 dark:text-neutral-100">{t('admin.storeEvents.offers')}</h2>
            <div className="grid gap-3">
                {event.offers.map((offer, i) => (
                    <OfferCard
                        // Keyed on the product AND its position, so the badge inputs
                        // (local state seeded from props) re-seed after a reorder
                        // instead of showing the previous row's text.
                        key={`${offer.product_id}-${i}`}
                        eventId={event.id}
                        offer={offer}
                        index={i}
                        total={event.offers.length}
                        onMove={move}
                    />
                ))}
                {event.offers.length === 0 && (
                    <div className={`${CARD} p-10 text-center text-sm text-neutral-500`}>{t('admin.storeEvents.noOffers')}</div>
                )}
            </div>
        </AdminLayout>
    );
}

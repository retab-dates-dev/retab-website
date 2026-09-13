import { Head, router, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ExternalLink, ImageOff, ImageUp, PackagePlus, Plus, Power, Search, Trash2, X } from 'lucide-react';
import { type FormEvent, type ReactNode, useEffect, useMemo, useRef, useState } from 'react';

import Button from '@/components/admin/button';
import StatusBadge from '@/components/admin/status-badge';
import StatusPill from '@/components/status-pill';
import { useCan } from '@/hooks/use-can';
import { useAdminT } from '@/i18n/use-admin-t';
import AdminLayout from '@/layouts/admin-layout';
import { CARD } from '@/lib/admin-ui';
import { normalize } from '@/lib/search';

import EventFields, { type EventForm, toInput } from './event-fields';
import { type EventRow } from './index';

/**
 * One store event: the whole campaign in one place — its own fields, its homepage
 * hero banners, the offers it fronts (attached from the catalogue or created on the
 * spot), and the pool to add from.
 *
 * 🔑 An ATTACHED offer's campaign data lives on the PIVOT, so attaching never edits
 * a product. A CREATED offer is a campaign-only product, born with an
 * `available_until` equal to the event's end so it leaves the store on its own.
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
    available_until: string | null;
    stock: number;
    is_active: boolean;
    badge_ar: string | null;
    badge_en: string | null;
    banner_image: string | null;
    has_banner: boolean;
    preview: string | null;
    sort_order: number;
}

interface Banner {
    id: number;
    image: string | null;
    image_mobile: string | null;
    product_id: number | null;
    alt_ar: string | null;
    alt_en: string | null;
    starts_at: string | null;
    ends_at: string | null;
    is_active: boolean;
    state: string;
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

type EventDetail = EventRow & { offers: Offer[]; banners: Banner[] };

const INPUT =
    'w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm text-neutral-900 outline-none focus:border-brand-gold focus:ring-1 focus:ring-brand-gold dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-100';

const FILE =
    'block w-full text-sm text-neutral-600 file:me-3 file:rounded-md file:border-0 file:bg-neutral-100 file:px-3 file:py-1.5 file:text-sm file:text-neutral-800 dark:text-neutral-300 dark:file:bg-neutral-800 dark:file:text-neutral-100';

/** `2026-10-01 00:00:00` → `2026-10-01 00:00`, for reading rather than editing. */
const readable = (value: string) => toInput(value).replace('T', ' ');

/**
 * Why a banner is or is not on the homepage. `offer_hidden` is the only one that
 * needs somebody to act, so it is the only loud one.
 */
const BANNER_TONE: Record<string, 'active' | 'idle' | 'done' | 'stopped' | 'attention'> = {
    live: 'active',
    scheduled: 'idle',
    ended: 'done',
    off: 'stopped',
    event_paused: 'stopped',
    offer_hidden: 'attention',
};

function Field({ label, hint, error, children }: { label: string; hint?: string; error?: string; children: ReactNode }) {
    return (
        <label className="block text-sm">
            <span className="mb-1 block text-neutral-700 dark:text-neutral-300">{label}</span>
            {children}
            {hint && <span className="mt-1 block text-[11px] text-neutral-500">{hint}</span>}
            {error && <span className="mt-1 block text-xs text-red-600">{error}</span>}
        </label>
    );
}

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
            {/* 2:1, matching the storefront card: campaign artwork is delivered in
                that shape, the same as the hero banners. */}
            <div className="relative aspect-[2/1] w-full shrink-0 overflow-hidden rounded-lg bg-neutral-100 sm:w-56 dark:bg-neutral-800">
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

                <div className="mt-1 flex flex-wrap items-center gap-3 text-xs text-neutral-500 tabular-nums">
                    <span>{t('admin.storeEvents.offer.stock', { n: offer.stock })}</span>
                    {offer.available_until && <span>{t('admin.storeEvents.offer.leavesAt', { date: readable(offer.available_until) })}</span>}
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

/**
 * Create a campaign-only product (a bundle) and attach it, in one step.
 *
 * Deliberately short — name, contents, price, stock, one photo. The product is born
 * in Special Offers with `available_until` = the event's end, so it leaves the store
 * on its own; anything more is the full product editor's job.
 */
function CreateOfferForm({ eventId, endsAt }: { eventId: number; endsAt: string }) {
    const { t } = useAdminT();
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        name_ar: '',
        name_en: '',
        description_ar: '',
        description_en: '',
        price: '',
        stock: '',
        image: null as File | null,
    });

    const preview = useMemo(() => (data.image ? URL.createObjectURL(data.image) : null), [data.image]);
    useEffect(
        () => () => {
            if (preview) URL.revokeObjectURL(preview);
        },
        [preview],
    );

    if (!open) {
        return (
            <Button icon={PackagePlus} onClick={() => setOpen(true)}>
                {t('admin.storeEvents.createOffer.open')}
            </Button>
        );
    }

    const close = () => {
        reset();
        clearErrors();
        setOpen(false);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        // Multipart (a photo), so POST with FormData.
        post(`/admin/store-events/${eventId}/offers/new`, { forceFormData: true, preserveScroll: true, onSuccess: close });
    };

    return (
        <form onSubmit={submit} className="rounded-lg border border-neutral-200 p-4 dark:border-neutral-800">
            <h3 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{t('admin.storeEvents.createOffer.title')}</h3>
            <p className="mt-1 mb-4 text-xs text-neutral-500">{t('admin.storeEvents.createOffer.hint', { date: readable(endsAt) })}</p>

            <div className="grid gap-4 sm:grid-cols-2">
                <Field label={t('admin.storeEvents.createOffer.nameAr')} error={errors.name_ar}>
                    <input className={INPUT} dir="rtl" value={data.name_ar} onChange={(e) => setData('name_ar', e.target.value)} />
                </Field>
                <Field label={t('admin.storeEvents.createOffer.nameEn')} error={errors.name_en}>
                    <input className={INPUT} dir="ltr" value={data.name_en} onChange={(e) => setData('name_en', e.target.value)} />
                </Field>
                <Field label={t('admin.storeEvents.createOffer.descriptionAr')} error={errors.description_ar}>
                    <textarea
                        className={INPUT}
                        dir="rtl"
                        rows={3}
                        value={data.description_ar}
                        onChange={(e) => setData('description_ar', e.target.value)}
                    />
                </Field>
                <Field label={t('admin.storeEvents.createOffer.descriptionEn')} error={errors.description_en}>
                    <textarea
                        className={INPUT}
                        dir="ltr"
                        rows={3}
                        value={data.description_en}
                        onChange={(e) => setData('description_en', e.target.value)}
                    />
                </Field>
                <Field label={t('admin.storeEvents.createOffer.price')} error={errors.price}>
                    <input
                        className={INPUT}
                        type="number"
                        min="0"
                        step="0.01"
                        value={data.price}
                        onChange={(e) => setData('price', e.target.value)}
                    />
                </Field>
                <Field label={t('admin.storeEvents.createOffer.stock')} error={errors.stock}>
                    <input className={INPUT} type="number" min="0" value={data.stock} onChange={(e) => setData('stock', e.target.value)} />
                </Field>
                <Field label={t('admin.storeEvents.createOffer.image')} hint={t('admin.storeEvents.createOffer.imageHint')} error={errors.image}>
                    <input className={FILE} type="file" accept="image/*" onChange={(e) => setData('image', e.target.files?.[0] ?? null)} />
                </Field>
                {preview && <img src={preview} alt="" className="aspect-square w-32 rounded-lg object-cover" />}
            </div>

            <div className="mt-4 flex justify-end gap-2">
                <Button variant="ghost" onClick={close}>
                    {t('admin.storeEvents.createOffer.cancel')}
                </Button>
                <Button type="submit" variant="primary" disabled={processing}>
                    {t('admin.storeEvents.createOffer.submit')}
                </Button>
            </div>
        </form>
    );
}

/** Link target options: the event's own offers, or the event's catalogue page. */
function LinkSelect({ value, offers, onChange }: { value: number | '' | null; offers: Offer[]; onChange: (id: number | null) => void }) {
    const { t } = useAdminT();

    return (
        <select className={INPUT} value={value ?? ''} onChange={(e) => onChange(e.target.value ? Number(e.target.value) : null)}>
            <option value="">{t('admin.storeEvents.banners.linkEvent')}</option>
            {offers.map((o) => (
                <option key={o.product_id} value={o.product_id}>
                    {o.name_ar}
                </option>
            ))}
        </select>
    );
}

/** One hero banner: its two images, why it is (or is not) live, its link and own window. */
function BannerRow({
    eventId,
    banner,
    offers,
    index,
    total,
    onMove,
}: {
    eventId: number;
    banner: Banner;
    offers: Offer[];
    index: number;
    total: number;
    onMove: (from: number, to: number) => void;
}) {
    const { t } = useAdminT();
    const base = `/admin/store-events/${eventId}/banners/${banner.id}`;
    const [starts, setStarts] = useState(toInput(banner.starts_at));
    const [ends, setEnds] = useState(toInput(banner.ends_at));
    const datesDirty = starts !== toInput(banner.starts_at) || ends !== toInput(banner.ends_at);
    const patch = (data: Record<string, string | number | boolean | null>) => router.patch(base, data, { preserveScroll: true });

    return (
        <div className={`${CARD} flex flex-col gap-3 p-3 lg:flex-row lg:items-start`}>
            <div className="flex shrink-0 items-end gap-2">
                <div className="aspect-[2/1] w-48 overflow-hidden rounded-lg bg-neutral-100 dark:bg-neutral-800">
                    {banner.image && <img src={banner.image} alt="" className="h-full w-full object-cover" />}
                </div>
                <div className="flex aspect-[4/5] w-14 items-center justify-center overflow-hidden rounded-md bg-neutral-100 dark:bg-neutral-800">
                    {banner.image_mobile ? (
                        <img src={banner.image_mobile} alt="" className="h-full w-full object-cover" />
                    ) : (
                        <span className="px-1 text-center text-[9px] leading-tight text-neutral-500">{t('admin.storeEvents.banners.noMobile')}</span>
                    )}
                </div>
            </div>

            <div className="min-w-0 flex-1 space-y-3">
                <StatusPill tone={BANNER_TONE[banner.state] ?? 'idle'}>{t(`admin.storeEvents.banners.state.${banner.state}`)}</StatusPill>

                <Field label={t('admin.storeEvents.banners.link')}>
                    <LinkSelect value={banner.product_id} offers={offers} onChange={(id) => patch({ product_id: id })} />
                </Field>

                <div className="grid gap-2 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                    <Field label={t('admin.storeEvents.banners.startsAt')}>
                        <input className={INPUT} type="datetime-local" value={starts} onChange={(e) => setStarts(e.target.value)} />
                    </Field>
                    <Field label={t('admin.storeEvents.banners.endsAt')}>
                        <input className={INPUT} type="datetime-local" value={ends} onChange={(e) => setEnds(e.target.value)} />
                    </Field>
                    <Button
                        size="sm"
                        variant="secondary"
                        disabled={!datesDirty}
                        onClick={() => patch({ starts_at: starts || null, ends_at: ends || null })}
                    >
                        {t('admin.storeEvents.banners.saveDates')}
                    </Button>
                </div>
                <p className="text-[11px] text-neutral-500">{t('admin.storeEvents.banners.windowHint')}</p>
            </div>

            <div className="flex shrink-0 flex-row flex-wrap gap-1 lg:flex-col">
                <Button size="sm" variant="secondary" icon={Power} onClick={() => patch({ is_active: !banner.is_active })}>
                    {banner.is_active ? t('admin.storeEvents.banners.switchOff') : t('admin.storeEvents.banners.switchOn')}
                </Button>
                <Button
                    size="sm"
                    variant="ghost"
                    icon={ArrowUp}
                    disabled={index === 0}
                    onClick={() => onMove(index, index - 1)}
                    aria-label={t('admin.storeEvents.banners.moveUp')}
                >
                    {''}
                </Button>
                <Button
                    size="sm"
                    variant="ghost"
                    icon={ArrowDown}
                    disabled={index === total - 1}
                    onClick={() => onMove(index, index + 1)}
                    aria-label={t('admin.storeEvents.banners.moveDown')}
                >
                    {''}
                </Button>
                <Button
                    size="sm"
                    variant="danger"
                    icon={Trash2}
                    onClick={() => router.delete(base, { preserveScroll: true })}
                    aria-label={t('admin.storeEvents.banners.remove')}
                >
                    {''}
                </Button>
            </div>
        </div>
    );
}

function AddBannerForm({ eventId, offers }: { eventId: number; offers: Offer[] }) {
    const { t } = useAdminT();
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        image: null as File | null,
        image_mobile: null as File | null,
        product_id: '' as number | '',
        alt_ar: '',
        alt_en: '',
        starts_at: '',
        ends_at: '',
    });

    if (!open) {
        return (
            <Button icon={Plus} onClick={() => setOpen(true)}>
                {t('admin.storeEvents.banners.add')}
            </Button>
        );
    }

    const close = () => {
        reset();
        clearErrors();
        setOpen(false);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        // Two files, so multipart POST.
        post(`/admin/store-events/${eventId}/banners`, { forceFormData: true, preserveScroll: true, onSuccess: close });
    };

    return (
        <form onSubmit={submit} className="rounded-lg border border-neutral-200 p-4 dark:border-neutral-800">
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label={t('admin.storeEvents.banners.desktop')} hint={t('admin.storeEvents.banners.desktopHint')} error={errors.image}>
                    <input className={FILE} type="file" accept="image/*" onChange={(e) => setData('image', e.target.files?.[0] ?? null)} />
                </Field>
                <Field label={t('admin.storeEvents.banners.mobile')} hint={t('admin.storeEvents.banners.mobileHint')} error={errors.image_mobile}>
                    <input className={FILE} type="file" accept="image/*" onChange={(e) => setData('image_mobile', e.target.files?.[0] ?? null)} />
                </Field>
                <Field label={t('admin.storeEvents.banners.link')} error={errors.product_id}>
                    <LinkSelect value={data.product_id} offers={offers} onChange={(id) => setData('product_id', id ?? '')} />
                </Field>
                <div />
                <Field label={t('admin.storeEvents.banners.altAr')} hint={t('admin.storeEvents.banners.altHint')} error={errors.alt_ar}>
                    <input className={INPUT} dir="rtl" value={data.alt_ar} onChange={(e) => setData('alt_ar', e.target.value)} />
                </Field>
                <Field label={t('admin.storeEvents.banners.altEn')} error={errors.alt_en}>
                    <input className={INPUT} dir="ltr" value={data.alt_en} onChange={(e) => setData('alt_en', e.target.value)} />
                </Field>
                <Field label={t('admin.storeEvents.banners.startsAt')} error={errors.starts_at}>
                    <input className={INPUT} type="datetime-local" value={data.starts_at} onChange={(e) => setData('starts_at', e.target.value)} />
                </Field>
                <Field label={t('admin.storeEvents.banners.endsAt')} hint={t('admin.storeEvents.banners.windowHint')} error={errors.ends_at}>
                    <input className={INPUT} type="datetime-local" value={data.ends_at} onChange={(e) => setData('ends_at', e.target.value)} />
                </Field>
            </div>

            <div className="mt-4 flex justify-end gap-2">
                <Button variant="ghost" onClick={close}>
                    {t('admin.storeEvents.createOffer.cancel')}
                </Button>
                <Button type="submit" variant="primary" disabled={processing || !data.image}>
                    {t('admin.storeEvents.banners.addSubmit')}
                </Button>
            </div>
        </form>
    );
}

export default function StoreEventShow({
    event,
    pool,
    accentPresets,
}: {
    event: EventDetail;
    pool: PoolProduct[];
    accentPresets: Record<string, string>;
}) {
    const { t } = useAdminT();
    const canCreateOffers = useCan()('products.create');
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

    const moveBanner = (from: number, to: number) => {
        const ids = event.banners.map((b) => b.id);
        const [moved] = ids.splice(from, 1);
        ids.splice(to, 0, moved);
        router.post(`/admin/store-events/${event.id}/banners/reorder`, { banner_ids: ids }, { preserveScroll: true });
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
                <h2 className="mb-1 text-sm font-semibold text-neutral-900 dark:text-neutral-100">{t('admin.storeEvents.banners.title')}</h2>
                <p className="mb-4 text-xs text-neutral-500">{t('admin.storeEvents.banners.hint')}</p>
                <div className="mb-4 grid gap-3">
                    {event.banners.map((banner, i) => (
                        <BannerRow
                            // Position in the key re-seeds the date inputs after a reorder,
                            // same reason as the offer cards.
                            key={`${banner.id}-${i}`}
                            eventId={event.id}
                            banner={banner}
                            offers={event.offers}
                            index={i}
                            total={event.banners.length}
                            onMove={moveBanner}
                        />
                    ))}
                    {event.banners.length === 0 && <p className="py-2 text-sm text-neutral-500">{t('admin.storeEvents.banners.empty')}</p>}
                </div>
                <AddBannerForm eventId={event.id} offers={event.offers} />
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

                {/* Only offered to staff who may create products: the endpoint needs
                    products.create as well as the event's own permission. */}
                {canCreateOffers && (
                    <div className="mt-5 border-t border-neutral-200 pt-5 dark:border-neutral-800">
                        <CreateOfferForm eventId={event.id} endsAt={event.ends_at} />
                    </div>
                )}
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

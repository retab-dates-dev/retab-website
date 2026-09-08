import CatalogueSearch from '@/components/store/catalogue-search';
import StorePagination, { type Paginator } from '@/components/store/pagination';
import ProductCard, { type StoreProduct } from '@/components/store/product-card';
import StoreSelect from '@/components/store/select';
import StoreLayout from '@/layouts/store-layout';
import { useLocalized } from '@/lib/localize';
import { type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Tag, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';

interface Category {
    id: number;
    name_ar: string;
    name_en: string | null;
    slug: string;
}

interface Filters {
    q: string;
    sort: 'newest' | 'price_asc' | 'price_desc' | 'name';
    on_sale: boolean;
}

// Filtering only ever changes these props — a partial reload leaves the navbar,
// category list, auth and every other shared prop untouched (no re-query, smaller
// payload, no remount), which is what makes it feel like an SPA instead of a
// page load. `categories` is deferred server-side, so it isn't in this list.
const FILTER_ONLY = ['products', 'filters', 'activeCategory', 'activeEvent'];

export default function ShopCatalogue({
    categories,
    products,
    activeCategory,
    activeEvent = null,
    filters,
}: {
    categories: Category[];
    products: Paginator<StoreProduct>;
    activeCategory: string | null;
    activeEvent?: { id: number; name_ar: string; name_en: string | null } | null;
    filters: Filters;
}) {
    const { t } = useTranslation();
    const localized = useLocalized();
    const { ogImage } = usePage<SharedData>().props;

    // Merge the current filters with a patch, dropping empties, and produce the
    // query object. Category filters are real links (crawlable); the search / sort
    // / offers controls navigate via router.get with the same merge.
    const params = (patch: Record<string, string | undefined>) => {
        const merged: Record<string, string | undefined> = {
            category: activeCategory ?? undefined,
            q: filters.q || undefined,
            sort: filters.sort !== 'newest' ? filters.sort : undefined,
            on_sale: filters.on_sale ? '1' : undefined,
            event: activeEvent ? String(activeEvent.id) : undefined,
            ...patch,
        };
        return Object.fromEntries(Object.entries(merged).filter(([, v]) => v !== undefined && v !== '')) as Record<string, string>;
    };

    const hrefWith = (patch: Record<string, string | undefined>) => {
        const qs = new URLSearchParams(params(patch)).toString();
        return qs ? `/shop?${qs}` : '/shop';
    };

    // Toolbar interactions stay put (preserveScroll) so the grid doesn't jump,
    // and only re-fetch the products list (partial reload).
    const go = (patch: Record<string, string | undefined>) =>
        router.get('/shop', params(patch), { preserveState: true, preserveScroll: true, only: FILTER_ONLY });

    const hasFilters = Boolean(filters.q || filters.on_sale || activeCategory || activeEvent || filters.sort !== 'newest');

    const chip = (active: boolean) =>
        `rounded-full px-4 py-1.5 text-sm font-medium transition-colors ${
            active ? 'bg-brand-teal text-white' : 'bg-brand-cream text-brand-teal hover:bg-brand-gold/20'
        }`;

    return (
        <StoreLayout>
            <Head title={t('catalogue.headTitle')}>
                <meta name="description" content={t('shop.metaDescription')} />
                <meta property="og:title" content={t('catalogue.headTitle')} />
                <meta property="og:type" content="website" />
                <meta property="og:image" content={ogImage} />
                <meta property="og:image:width" content="1200" />
                <meta property="og:image:height" content="630" />
                <meta name="twitter:card" content="summary_large_image" />
            </Head>

            <h1 className="font-heading text-brand-teal mb-6 text-center text-[clamp(1.75rem,4vw,2.75rem)] font-black">
                {activeEvent ? localized(activeEvent, 'name') : t('catalogue.heading')}
            </h1>

            {/* Category chips — real <a> links (crawlable) that visit as partial
                reloads, prefetched on hover so the click feels instant. */}
            <div className="mb-6 flex flex-wrap justify-center gap-2">
                <Link
                    href={hrefWith({ category: undefined })}
                    only={FILTER_ONLY}
                    preserveState
                    preserveScroll
                    prefetch
                    className={chip(!activeCategory)}
                >
                    {t('catalogue.all')}
                </Link>
                {categories.map((c) => (
                    <Link
                        key={c.id}
                        href={hrefWith({ category: c.slug })}
                        only={FILTER_ONLY}
                        preserveState
                        preserveScroll
                        prefetch
                        className={chip(activeCategory === c.slug)}
                    >
                        {localized(c, 'name')}
                    </Link>
                ))}
            </div>

            {/* Toolbar: search · offers toggle · sort */}
            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <CatalogueSearch initialQuery={filters.q} onSubmit={(term) => go({ q: term || undefined })} />

                <div className="flex items-center gap-3">
                    <button
                        type="button"
                        onClick={() => go({ on_sale: filters.on_sale ? undefined : '1' })}
                        aria-pressed={filters.on_sale}
                        className={`inline-flex items-center gap-1.5 rounded-full border px-4 py-2 text-sm font-medium transition-colors ${
                            filters.on_sale
                                ? 'border-brand-gold bg-brand-gold text-white'
                                : 'border-brand-gold/30 text-brand-teal hover:bg-brand-gold/10'
                        }`}
                    >
                        <Tag className="size-4" />
                        {t('catalogue.offersOnly')}
                    </button>

                    <StoreSelect
                        value={filters.sort}
                        onValueChange={(v) => go({ sort: v === 'newest' ? undefined : v })}
                        ariaLabel={t('catalogue.sortLabel')}
                        options={[
                            { value: 'newest', label: t('catalogue.sortNewest') },
                            { value: 'price_asc', label: t('catalogue.sortPriceAsc') },
                            { value: 'price_desc', label: t('catalogue.sortPriceDesc') },
                            { value: 'name', label: t('catalogue.sortName') },
                        ]}
                    />
                </div>
            </div>

            {/* Result count + clear */}
            <div className="text-brand-teal/70 mb-6 flex items-center justify-between gap-3 text-sm">
                <span>{t('catalogue.resultCount', { n: products.total })}</span>
                {hasFilters && (
                    <Link
                        href="/shop"
                        only={FILTER_ONLY}
                        preserveState
                        preserveScroll
                        className="text-brand-gold hover:text-brand-teal inline-flex items-center gap-1"
                    >
                        <X className="size-3.5" />
                        {t('catalogue.clearFilters')}
                    </Link>
                )}
            </div>

            {products.data.length === 0 ? (
                <p className="text-brand-teal/60 py-16 text-center">{filters.q ? t('catalogue.noResults') : t('shop.empty')}</p>
            ) : (
                <div className="grid grid-cols-2 gap-x-4 gap-y-8 sm:grid-cols-3 lg:grid-cols-4">
                    {products.data.map((p) => (
                        <ProductCard key={p.id} product={p} />
                    ))}
                </div>
            )}

            <StorePagination paginator={products} only={['products']} />
        </StoreLayout>
    );
}

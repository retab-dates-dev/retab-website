import BestSellers from '@/components/store/best-sellers';
import CategoriesSection from '@/components/store/categories-section';
import ClientLogos from '@/components/store/client-logos';
import ClientReviews from '@/components/store/client-reviews';
import FooterBanner from '@/components/store/footer-banner';
import StoreHero from '@/components/store/hero';
import NewArrivals from '@/components/store/new-arrivals';
import Offers from '@/components/store/offers';
import PrimaryBanner from '@/components/store/primary-banner';
import StoreEventSection, { type StoreEventPayload } from '@/components/store/store-event';
import StoreLayout from '@/layouts/store-layout';
import { type SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

/**
 * ⏸️ HIDDEN 2026-08-15 at the client's request — flip to `true` to bring it back.
 *
 * The "جودة يمكنك الوثوق بها" trust-badge banner that used to close the homepage.
 * Kept wired up rather than deleted because the decision is not final: it may be
 * restored or dropped for good later. Everything it needs is still in place —
 * `components/store/footer-banner.tsx`, `public/images/footer-banner/banner.webp`,
 * and the `footerBanner.*` copy in both i18n bundles — so restoring it is this one
 * word and nothing else.
 *
 * If it is ever dropped for good, remove all four of those together.
 */
const SHOW_FOOTER_BANNER = false;

interface ProductCard {
    id: number;
    name_ar: string;
    name_en: string | null;
    slug: string;
    price: number;
    sale_price: number | null;
    effective_price: number;
    on_sale: boolean;
    is_featured: boolean;
    image: string | null;
    category: { name_ar: string; name_en: string | null; slug: string } | null;
}

interface FeaturedCategory {
    id: number;
    name_ar: string;
    name_en: string | null;
    slug: string;
    image: string | null;
}

interface ReviewItem {
    id: number;
    author_name: string;
    body: string;
    rating: number;
}

export default function ShopIndex({
    bestSellers = [],
    storeEvents = [],
    offers = [],
    newArrivals = [],
    featuredCategories = [],
    reviews = [],
}: {
    bestSellers?: ProductCard[];
    storeEvents?: StoreEventPayload[];
    offers?: ProductCard[];
    newArrivals?: ProductCard[];
    featuredCategories?: FeaturedCategory[];
    reviews?: ReviewItem[];
}) {
    const { t } = useTranslation();
    const { ogImage } = usePage<SharedData>().props;

    return (
        <StoreLayout bare>
            {/* The home page is the link customers actually share on WhatsApp, so
                it carries the brand share card. Declaring the dimensions lets a
                crawler lay the preview out before the image finishes downloading. */}
            <Head title={t('shop.headTitle')}>
                <meta name="description" content={t('shop.metaDescription')} />
                <meta property="og:title" content={t('shop.headTitle')} />
                <meta property="og:description" content={t('shop.metaDescription')} />
                <meta property="og:type" content="website" />
                <meta property="og:image" content={ogImage} />
                <meta property="og:image:width" content="1200" />
                <meta property="og:image:height" content="630" />
                <meta name="twitter:card" content="summary_large_image" />
            </Head>

            <StoreHero />
            {/* Named campaigns sit ABOVE Best Sellers and are absent entirely
                between events, so the page returns to its ordinary shape. Two
                overlapping events render two titled sections in order, which needs
                no "which one wins" rule. Distinct from <Offers/> lower down: that
                strip is ordinary discounts, and a product cannot be in both. */}
            {storeEvents.map((event) => (
                <StoreEventSection key={event.id} event={event} />
            ))}
            <BestSellers products={bestSellers} />
            <Offers products={offers} />
            <CategoriesSection categories={featuredCategories} />
            <PrimaryBanner />
            <NewArrivals products={newArrivals} />
            <ClientReviews reviews={reviews} />
            <ClientLogos />
            {SHOW_FOOTER_BANNER && <FooterBanner />}
        </StoreLayout>
    );
}

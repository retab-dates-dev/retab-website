import Button from '@/components/admin/button';
import { useModalActionsSlot } from '@/components/admin/modal';
import PageHeader from '@/components/admin/page-header';
import ProductOptionsEditor, { type OptionRow } from '@/components/admin/product-options-editor';
import Select from '@/components/admin/select';
import { useHighlightFields } from '@/hooks/use-highlight-fields';
import { useAdminT } from '@/i18n/use-admin-t';
import { router, useForm } from '@inertiajs/react';
import { Eye, Image as ImageIcon, Info, Sparkles, Star, Tag, Trash2, Upload } from 'lucide-react';
import { type FormEvent, type ReactNode, useEffect, useId, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';

export interface Category {
    id: number;
    name_ar: string;
    name_en: string | null;
}

export interface ProductImage {
    id: number;
    url: string | null;
    is_primary: boolean;
}

export interface Product {
    id: number;
    category_id: number;
    name_ar: string;
    name_en: string | null;
    slug: string | null;
    description_ar: string | null;
    description_en: string | null;
    price: number;
    base_weight_grams: number | null;
    sale_price: number | null;
    sku: string;
    smacc_sku: string | null;
    barcode: string | null;
    stock: number;
    low_stock_threshold: number | null;
    is_active: boolean;
    is_featured: boolean;
    is_coming_soon: boolean;
    /** `YYYY-MM-DD HH:MM:SS`, or null for a product with no end date. */
    available_until?: string | null;
    sale_applies_to_options?: boolean;
    images?: ProductImage[];
    options?: OptionRow[];
}

const INPUT = 'mt-1 w-full rounded border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800';
const CARD = 'space-y-4 rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900';

/**
 * The product create/edit form body (details, pricing, visibility, images),
 * shared by the full page and the in-list modal. In `modal` mode, saves and
 * image edits preserve state and call back (close / re-fetch). Every product
 * must have at least one image: on create it's collected + sent with the form;
 * on edit the Save button is blocked while the product has no images.
 */
export default function ProductFormBody({
    product,
    categories,
    modal = false,
    onSaved,
    onImageChanged,
    title,
    back,
    backLabel,
}: {
    product: Product | null;
    categories: Category[];
    modal?: boolean;
    onSaved?: () => void;
    onImageChanged?: () => void;
    /** Page-mode header. Ignored in `modal` mode, which has its own chrome. */
    title?: ReactNode;
    back?: string;
    backLabel?: ReactNode;
}) {
    const { t, i18n } = useAdminT();
    const editing = product !== null;
    useHighlightFields();
    // Ties the header's Save button to the <form> it sits outside of.
    const formId = useId();
    const modalSlot = useModalActionsSlot();

    // EN-first admin: show a category's English name when set, else the Arabic.
    const catLabel = (c: Category) => (i18n.language === 'en' && c.name_en ? c.name_en : c.name_ar);

    // ---- form helpers: suggest an English name / a description ---------------
    // Both are computed server-side so the dictionaries live in exactly one
    // place (app/Support/ProductNameTranslator + ProductDescriptionWriter).
    // Porting them here would duplicate them, which is the drift this codebase
    // has already been bitten by with the search `normalize`.
    const [suggesting, setSuggesting] = useState<null | 'name' | 'description'>(null);
    const [suggestFailed, setSuggestFailed] = useState<null | 'name' | 'description'>(null);

    const suggest = async (what: 'name' | 'description') => {
        setSuggesting(what);
        setSuggestFailed(null);
        try {
            const res = await fetch('/admin/products/suggest', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    // ⚠️ This app has NO <meta name="csrf-token"> — the token comes
                    // from the XSRF-TOKEN cookie, as in LanguageContext. Reading a
                    // meta tag that does not exist sends an empty token and the
                    // request 419s silently.
                    'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
                    Accept: 'application/json',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ name_ar: data.name_ar, name_en: data.name_en, category_id: data.category_id || null }),
            });
            if (!res.ok) throw new Error(String(res.status));
            const json = await res.json();

            if (what === 'name') {
                // null when a word is not in the dictionary — say so rather than
                // pasting a confidently-wrong name the admin might not re-read.
                if (json.name_en) setData('name_en', json.name_en);
                else setSuggestFailed('name');
            } else {
                setData('description_ar', json.description.ar);
                setData('description_en', json.description.en);
            }
        } catch {
            setSuggestFailed(what);
        } finally {
            setSuggesting(null);
        }
    };

    const { data, setData, post, put, processing, errors, isDirty, transform } = useForm({
        category_id: product?.category_id ?? categories[0]?.id ?? '',
        name_ar: product?.name_ar ?? '',
        name_en: product?.name_en ?? '',
        slug: product?.slug ?? '',
        description_ar: product?.description_ar ?? '',
        description_en: product?.description_en ?? '',
        price: product?.price ?? '',
        base_weight_grams: product?.base_weight_grams ?? '',
        sale_price: product?.sale_price ?? '',
        sku: product?.sku ?? '',
        smacc_sku: product?.smacc_sku ?? '',
        barcode: product?.barcode ?? '',
        stock: product?.stock ?? 0,
        low_stock_threshold: product?.low_stock_threshold ?? '',
        is_active: product?.is_active ?? true,
        is_featured: product?.is_featured ?? false,
        is_coming_soon: product?.is_coming_soon ?? false,
        // `datetime-local` will not accept `2026-10-01 00:00:00` (space + seconds)
        // and renders EMPTY instead, which reads as the date having been lost.
        available_until: product?.available_until ? product.available_until.replace(' ', 'T').slice(0, 16) : '',
        sale_applies_to_options: product?.sale_applies_to_options ?? false,
        options: (product?.options ?? []) as OptionRow[],
        images: [] as File[], // create only — the new product's images, sent with the form
    });

    // Every product needs at least one image (create: chosen files; edit: existing).
    const hasImages = editing ? (product?.images?.length ?? 0) > 0 : data.images.length > 0;

    // Object-URL previews for the create picker (revoked when the selection changes).
    const previews = useMemo(() => data.images.map((f) => URL.createObjectURL(f)), [data.images]);
    useEffect(() => () => previews.forEach((u) => URL.revokeObjectURL(u)), [previews]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!hasImages) return;
        if (editing) {
            // Edit is a JSON PUT — options go as a plain array (real booleans, null
            // amounts), which Laravel parses directly.
            put(`/admin/products/${product.id}`, modal ? { preserveScroll: true, preserveState: true, onSuccess: onSaved } : {});
        } else {
            // Booleans as '1'/'0' so they survive the multipart (FormData) encoding —
            // top-level and inside each option row. A null amount is sent empty.
            transform((d) => ({
                ...d,
                is_active: d.is_active ? '1' : '0',
                is_featured: d.is_featured ? '1' : '0',
                is_coming_soon: d.is_coming_soon ? '1' : '0',
                sale_applies_to_options: d.sale_applies_to_options ? '1' : '0',
                options: (d.options as OptionRow[]).map((o) => ({
                    ...o,
                    amount: o.amount ?? '',
                    price_overridden: o.price_overridden ? '1' : '0',
                    is_active: o.is_active ? '1' : '0',
                    is_box: o.is_box ? '1' : '0',
                })),
            }));
            post('/admin/products', {
                forceFormData: true,
                preserveScroll: true,
                ...(modal ? { preserveState: true, onSuccess: onSaved } : {}),
            });
        }
    };

    // Existing-image edits (edit mode). In modal, keep the modal open + re-fetch.
    const imgOpts = modal ? { preserveScroll: true, preserveState: true, onSuccess: onImageChanged } : { preserveScroll: true };
    const imageForm = useForm<{ images: File[] }>({ images: [] });

    const uploadImages = (e: FormEvent) => {
        e.preventDefault();
        if (!product || imageForm.data.images.length === 0) return;
        imageForm.post(`/admin/products/${product.id}/images`, {
            forceFormData: true,
            preserveScroll: true,
            preserveState: modal,
            onSuccess: () => {
                imageForm.reset('images');
                onImageChanged?.();
            },
        });
    };

    const setPrimary = (imageId: number) => product && router.put(`/admin/products/${product.id}/images/${imageId}/primary`, {}, imgOpts);
    const deleteImage = (imageId: number) => product && router.delete(`/admin/products/${product.id}/images/${imageId}`, imgOpts);

    const text = (name: keyof typeof data, label: string, opts: { required?: boolean; type?: string; placeholder?: string } = {}) => (
        <label className="flex h-full flex-col" id={`field-${name}`}>
            <span className="text-sm text-neutral-600 dark:text-neutral-300">
                {label}
                {opts.required && <span className="text-red-500"> *</span>}
            </span>
            {/* 🔑 `mt-auto` pins every box to the BOTTOM of its grid cell, so a label
                that wraps to two lines ("Low-stock threshold" at narrow widths) no
                longer shoves its input out of line with the rest of the row. The
                wrapper carries the push while the input keeps its own top margin,
                which guarantees a gap even when the label is the tallest in the row. */}
            <div className="mt-auto">
                <input
                    type={opts.type ?? 'text'}
                    value={data[name] as string | number}
                    placeholder={opts.placeholder}
                    onChange={(e) => setData(name, e.target.value)}
                    className={INPUT}
                />
            </div>
            {errors[name] && <span className="text-xs text-red-500">{errors[name]}</span>}
        </label>
    );

    // One button, rendered either in the page header or, in a modal, under the form.
    const saveButton = (
        <Button type="submit" form={formId} variant="primary" disabled={processing || !isDirty || !hasImages}>
            {editing ? t('admin.products.form.saveChanges') : t('admin.products.form.createProduct')}
        </Button>
    );
    const imageWarning = !hasImages ? <span className="text-xs text-red-500">{t('admin.products.form.imageRequired')}</span> : null;

    return (
        <div className="space-y-6">
            {!modal ? (
                <PageHeader
                    title={title}
                    back={back}
                    backLabel={backLabel}
                    actions={
                        <>
                            {imageWarning}
                            {saveButton}
                        </>
                    }
                />
            ) : (
                // In a modal, Save is portalled up onto the dialog's own title row,
                // beside the close button, rather than taking a row of its own.
                modalSlot &&
                createPortal(
                    <>
                        {imageWarning}
                        {saveButton}
                    </>,
                    modalSlot,
                )
            )}

            {/* Two columns on wide screens: the main form on the left, visibility +
                images on the right. Stacks on narrow / mobile. */}
            <div className="grid gap-6 lg:grid-cols-[1.7fr_1fr] lg:items-start">
                <form id={formId} onSubmit={submit} className="space-y-6">
                    <section className={CARD}>
                        <h2 className="flex items-center gap-2 font-bold">
                            <Info className="text-brand-gold h-4 w-4" /> {t('admin.products.form.details')}
                        </h2>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <label className="block" id="field-category_id">
                                <span className="text-sm text-neutral-600 dark:text-neutral-300">{t('admin.products.form.category')} *</span>
                                <Select
                                    value={String(data.category_id)}
                                    onChange={(v) => setData('category_id', Number(v))}
                                    options={categories.map((c) => ({ value: String(c.id), label: catLabel(c) }))}
                                    className="mt-1 w-full"
                                />
                                {errors.category_id && <span className="text-xs text-red-500">{errors.category_id}</span>}
                            </label>
                            {text('slug', t('admin.products.form.slug'), { placeholder: t('admin.products.form.slugPlaceholder') })}
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            {text('name_ar', t('admin.products.form.nameAr'), { required: true })}
                            <div>
                                {text('name_en', t('admin.products.form.nameEn'))}
                                {/* Offered only while the field is EMPTY. A form has no
                                    undo, so a suggestion must never overwrite typed copy. */}
                                {!data.name_en.trim() && (
                                    <button
                                        type="button"
                                        onClick={() => suggest('name')}
                                        disabled={!data.name_ar.trim() || suggesting !== null}
                                        className="text-brand-gold mt-1.5 inline-flex items-center gap-1 text-xs hover:underline disabled:cursor-not-allowed disabled:text-neutral-500 disabled:no-underline"
                                    >
                                        <Sparkles className="h-3 w-3" />
                                        {suggesting === 'name' ? t('admin.products.form.suggesting') : t('admin.products.form.suggestNameEn')}
                                    </button>
                                )}
                                {suggestFailed === 'name' && (
                                    <span className="mt-1.5 block text-xs text-amber-600 dark:text-amber-400">
                                        {t('admin.products.form.suggestNameFailed')}
                                    </span>
                                )}
                            </div>
                        </div>
                        {/* Same rule: only while BOTH boxes are empty. */}
                        {!data.description_ar.trim() && !data.description_en.trim() && (
                            <button
                                type="button"
                                onClick={() => suggest('description')}
                                disabled={!data.name_ar.trim() || suggesting !== null}
                                className="text-brand-gold -mb-1 inline-flex items-center gap-1 self-start text-xs hover:underline disabled:cursor-not-allowed disabled:text-neutral-500 disabled:no-underline"
                            >
                                <Sparkles className="h-3 w-3" />
                                {suggesting === 'description' ? t('admin.products.form.suggesting') : t('admin.products.form.suggestDescription')}
                            </button>
                        )}
                        {suggestFailed === 'description' && (
                            <span className="text-xs text-amber-600 dark:text-amber-400">{t('admin.products.form.suggestFailed')}</span>
                        )}
                        <div className="grid gap-4 sm:grid-cols-2">
                            <label className="block" id="field-description_ar">
                                <span className="text-sm text-neutral-600 dark:text-neutral-300">{t('admin.products.form.descAr')}</span>
                                <textarea
                                    value={data.description_ar}
                                    onChange={(e) => setData('description_ar', e.target.value)}
                                    rows={4}
                                    className={INPUT}
                                />
                            </label>
                            <label className="block" id="field-description_en">
                                <span className="text-sm text-neutral-600 dark:text-neutral-300">{t('admin.products.form.descEn')}</span>
                                <textarea
                                    value={data.description_en}
                                    onChange={(e) => setData('description_en', e.target.value)}
                                    rows={4}
                                    className={INPUT}
                                />
                            </label>
                        </div>
                    </section>

                    <section className={CARD}>
                        <h2 className="flex items-center gap-2 font-bold">
                            <Tag className="text-brand-gold h-4 w-4" /> {t('admin.products.form.pricingInventory')}
                        </h2>
                        {/* Numbers grouped into one narrow 4-across row, identifiers into
                        a 3-across row — smaller fields, cleaner alignment. */}
                        <div className="grid grid-cols-2 gap-4 lg:grid-cols-5">
                            {text('price', t('admin.products.form.priceSar'), { required: true, type: 'number' })}
                            {/* Sits beside Price because it says what that price is FOR.
                                Optional: empty leaves the storefront's first choice
                                labelled generically, as it was before. */}
                            {text('base_weight_grams', t('admin.products.form.baseSize'), { type: 'number' })}
                            {text('sale_price', t('admin.products.form.salePriceSar'), { type: 'number' })}
                            {text('stock', t('admin.products.form.stock'), { required: true, type: 'number' })}
                            {text('low_stock_threshold', t('admin.products.form.lowStock'), { type: 'number' })}
                        </div>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            {text('sku', t('admin.products.form.sku'), { required: true })}
                            {text('smacc_sku', t('admin.products.form.smaccSku'))}
                            {text('barcode', t('admin.products.form.barcode'))}
                        </div>

                        <div className="border-t border-neutral-100 pt-4 dark:border-neutral-800">
                            <ProductOptionsEditor
                                value={data.options}
                                onChange={(rows) => setData('options', rows)}
                                basePrice={Number(data.price) || 0}
                            />

                            {/* A discount is on the original price by default; this opts it
                            into the sizes. Only meaningful once there are options AND a
                            sale price is set. */}
                            {data.options.length > 0 && (
                                <label
                                    className={`mt-3 flex items-start gap-2 text-sm ${data.sale_price === '' || data.sale_price === null ? 'opacity-50' : ''}`}
                                >
                                    <input
                                        type="checkbox"
                                        checked={data.sale_applies_to_options}
                                        onChange={(e) => setData('sale_applies_to_options', e.target.checked)}
                                        className="accent-brand-gold mt-0.5"
                                    />
                                    <span>
                                        {t('admin.products.options.saleApplies')}
                                        <span className="mt-0.5 block text-xs text-neutral-500">{t('admin.products.options.saleAppliesHint')}</span>
                                    </span>
                                </label>
                            )}
                        </div>
                    </section>
                </form>

                {/* Right column: visibility + images. These live OUTSIDE the <form>
                    element on purpose — the fields bind to `data` (Inertia useForm),
                    so submit still includes them, and the edit-mode uploader has its
                    own nested <form> which cannot sit inside the main one. */}
                <div className="space-y-6">
                    <section className="space-y-3 rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                        <h2 className="flex items-center gap-2 font-bold">
                            <Eye className="text-brand-gold h-4 w-4" /> {t('admin.products.form.visibility')}
                        </h2>
                        <label className="flex items-center gap-2 text-sm" id="field-is_active">
                            <input
                                type="checkbox"
                                checked={data.is_active}
                                onChange={(e) => setData('is_active', e.target.checked)}
                                className="accent-brand-gold"
                            />
                            {t('admin.products.form.activeLabel')}
                        </label>
                        <label className="flex items-center gap-2 text-sm" id="field-is_featured">
                            <input
                                type="checkbox"
                                checked={data.is_featured}
                                onChange={(e) => setData('is_featured', e.target.checked)}
                                className="accent-brand-gold"
                            />
                            {t('admin.products.form.featuredLabel')}
                        </label>
                        <label className="flex items-start gap-2 text-sm" id="field-is_coming_soon">
                            <input
                                type="checkbox"
                                checked={data.is_coming_soon}
                                onChange={(e) => setData('is_coming_soon', e.target.checked)}
                                className="accent-brand-gold mt-0.5"
                            />
                            <span>
                                {t('admin.products.form.comingSoonLabel')}
                                <span className="block text-xs text-neutral-400">{t('admin.products.form.comingSoonHint')}</span>
                            </span>
                        </label>
                        {/* Campaign products (store-event offers) take themselves off the
                            store at this time; see Product's saving guard and the
                            every-minute `catalog:hide-expired`. */}
                        <label className="block text-sm" id="field-available_until">
                            <span>{t('admin.products.form.availableUntil')}</span>
                            <input
                                type="datetime-local"
                                value={data.available_until}
                                onChange={(e) => setData('available_until', e.target.value)}
                                className={INPUT}
                            />
                            <span className="mt-1 block text-xs text-neutral-400">{t('admin.products.form.availableUntilHint')}</span>
                            {errors.available_until && <span className="mt-1 block text-xs text-red-600">{errors.available_until}</span>}
                        </label>
                    </section>

                    {/* On create, images are chosen here and sent with the product. */}
                    {!editing && (
                        <section className={CARD} id="field-images">
                            <h2 className="flex items-center gap-2 font-bold">
                                <ImageIcon className="text-brand-gold h-4 w-4" /> {t('admin.products.form.images')}{' '}
                                <span className="text-red-500">*</span>
                            </h2>
                            {previews.length > 0 && (
                                <div className="grid grid-cols-3 gap-3 sm:grid-cols-4">
                                    {previews.map((url, i) => (
                                        <img
                                            key={i}
                                            src={url}
                                            alt=""
                                            className={`aspect-square w-full rounded-lg border object-cover ${i === 0 ? 'border-brand-gold' : 'border-neutral-200 dark:border-neutral-700'}`}
                                        />
                                    ))}
                                </div>
                            )}
                            <div className="flex flex-wrap items-center gap-3">
                                <input
                                    type="file"
                                    aria-label={t('admin.products.form.selectImages')}
                                    accept="image/jpeg,image/png,image/webp,image/gif"
                                    multiple
                                    onChange={(e) => setData('images', Array.from(e.target.files ?? []))}
                                    className="text-sm"
                                />
                                <span className="text-xs text-neutral-400">{t('admin.products.form.selectImages')}</span>
                            </div>
                            {errors['images' as keyof typeof errors] && (
                                <p className="text-xs text-red-500">{errors['images' as keyof typeof errors]}</p>
                            )}
                        </section>
                    )}

                    {/* Existing product images (edit only) — managed via dedicated endpoints. */}
                    {editing && (
                        <section className={CARD}>
                            <h2 className="flex items-center gap-2 font-bold">
                                <ImageIcon className="text-brand-gold h-4 w-4" /> {t('admin.products.form.images')}
                            </h2>

                            {product.images && product.images.length > 0 ? (
                                <div className="grid grid-cols-3 gap-3 sm:grid-cols-4">
                                    {product.images.map((img) => (
                                        <div
                                            key={img.id}
                                            className={`relative overflow-hidden rounded-lg border ${img.is_primary ? 'border-brand-gold' : 'border-neutral-200 dark:border-neutral-700'}`}
                                        >
                                            {img.url && <img src={img.url} alt="" className="aspect-square w-full object-cover" />}
                                            <div className="flex items-center justify-between gap-1 p-1.5">
                                                {img.is_primary ? (
                                                    <span title={t('admin.products.form.primary')} className="text-brand-gold">
                                                        <Star className="h-4 w-4 fill-current" />
                                                    </span>
                                                ) : (
                                                    <button
                                                        type="button"
                                                        onClick={() => setPrimary(img.id)}
                                                        title={t('admin.products.form.setPrimary')}
                                                        className="hover:text-brand-gold text-neutral-400 transition-colors"
                                                    >
                                                        <Star className="h-4 w-4" />
                                                    </button>
                                                )}
                                                <button
                                                    type="button"
                                                    onClick={() => deleteImage(img.id)}
                                                    title={t('admin.common.delete')}
                                                    className="text-neutral-400 transition-colors hover:text-red-500"
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </button>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p className="text-sm text-red-500">{t('admin.products.form.imageRequired')}</p>
                            )}

                            <form
                                onSubmit={uploadImages}
                                className="flex flex-wrap items-center gap-3 border-t border-neutral-100 pt-4 dark:border-neutral-800"
                            >
                                <input
                                    type="file"
                                    aria-label={t('admin.products.form.selectImages')}
                                    accept="image/jpeg,image/png,image/webp,image/gif"
                                    multiple
                                    onChange={(e) => imageForm.setData('images', Array.from(e.target.files ?? []))}
                                    className="text-sm"
                                />
                                <Button
                                    type="submit"
                                    variant="primary"
                                    icon={Upload}
                                    disabled={imageForm.processing || imageForm.data.images.length === 0}
                                >
                                    {t('admin.common.upload')}
                                </Button>
                            </form>
                            {imageForm.errors.images && <p className="text-xs text-red-500">{imageForm.errors.images}</p>}
                        </section>
                    )}
                </div>
            </div>
        </div>
    );
}

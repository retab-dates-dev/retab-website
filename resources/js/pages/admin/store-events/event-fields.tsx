import { Check } from 'lucide-react';

import { useAdminT } from '@/i18n/use-admin-t';

export interface EventForm {
    name_ar: string;
    name_en: string;
    subtitle_ar: string;
    subtitle_en: string;
    starts_at: string;
    ends_at: string;
    accent_color: string;
    is_active: boolean;
    sort_order: number;
}

/** A week-long event starting today — the shape almost every campaign takes. */
export function blankEvent(): EventForm {
    const start = new Date();
    const end = new Date(Date.now() + 7 * 86_400_000);
    return {
        name_ar: '',
        name_en: '',
        subtitle_ar: '',
        subtitle_en: '',
        starts_at: toInput(start.toISOString()),
        ends_at: toInput(end.toISOString()),
        accent_color: '',
        is_active: true,
        sort_order: 0,
    };
}

/**
 * `datetime-local` will not accept `2026-09-08 12:00:00` (a space, and seconds) and
 * silently renders an EMPTY field rather than erroring — which reads as the date
 * having been lost. Trim to the `YYYY-MM-DDTHH:mm` it does accept.
 */
export function toInput(value: string | null): string {
    if (!value) return '';
    return value.replace(' ', 'T').slice(0, 16);
}

function Field({ label, hint, children }: { label: string; hint?: string; children: React.ReactNode }) {
    return (
        <label className="block">
            <span className="mb-1 block text-xs font-medium text-neutral-600 dark:text-neutral-300">{label}</span>
            {children}
            {hint && <span className="mt-1 block text-[11px] text-neutral-500">{hint}</span>}
        </label>
    );
}

const INPUT =
    'w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm text-neutral-900 outline-none focus:border-brand-gold focus:ring-1 focus:ring-brand-gold dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-100';

/**
 * The event's own fields, shared by the create dialog and the event page so the two
 * cannot drift apart on validation hints or on what a blank event starts as.
 */
export default function EventFields({
    form,
    onChange,
    accentPresets,
}: {
    form: EventForm;
    onChange: (form: EventForm) => void;
    accentPresets: Record<string, string>;
}) {
    const { t } = useAdminT();
    const set = <K extends keyof EventForm>(key: K, value: EventForm[K]) => onChange({ ...form, [key]: value });

    // Empty means "use the brand default", which is a real choice rather than a
    // missing one — so it is offered as the first swatch instead of being a gap.
    //
    // ⚠️ Any preset equal to the brand colour is then dropped: it would render an
    // identical second swatch beside the default one, and two swatches that look
    // the same but store different values ('' vs '#1b4e53') is a coin toss with no
    // visible difference.
    const brand = accentPresets.brand;
    const swatches: [string, string][] = [['default', ''], ...Object.entries(accentPresets).filter(([, hex]) => hex !== brand)];

    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t('admin.storeEvents.fields.nameAr')} hint={t('admin.storeEvents.fields.nameHint')}>
                <input className={INPUT} value={form.name_ar} onChange={(e) => set('name_ar', e.target.value)} dir="rtl" />
            </Field>
            <Field label={t('admin.storeEvents.fields.nameEn')}>
                <input className={INPUT} value={form.name_en} onChange={(e) => set('name_en', e.target.value)} dir="ltr" />
            </Field>

            <Field label={t('admin.storeEvents.fields.subtitleAr')} hint={t('admin.storeEvents.fields.subtitleHint')}>
                <input className={INPUT} value={form.subtitle_ar} onChange={(e) => set('subtitle_ar', e.target.value)} dir="rtl" />
            </Field>
            <Field label={t('admin.storeEvents.fields.subtitleEn')}>
                <input className={INPUT} value={form.subtitle_en} onChange={(e) => set('subtitle_en', e.target.value)} dir="ltr" />
            </Field>

            <Field label={t('admin.storeEvents.fields.startsAt')}>
                <input type="datetime-local" className={INPUT} value={form.starts_at} onChange={(e) => set('starts_at', e.target.value)} />
            </Field>
            <Field label={t('admin.storeEvents.fields.endsAt')} hint={t('admin.storeEvents.fields.endsHint')}>
                <input type="datetime-local" className={INPUT} value={form.ends_at} onChange={(e) => set('ends_at', e.target.value)} />
            </Field>

            <div className="sm:col-span-2">
                <span className="mb-1.5 block text-xs font-medium text-neutral-600 dark:text-neutral-300">
                    {t('admin.storeEvents.fields.accent')}
                </span>
                {/* 🔑 Presets rather than an open colour picker. The section's heading,
                    rule, badges and prices all take this colour, so a free field is a
                    licence to put a clashing one on the homepage. A one-off is still
                    possible through the hex box beside them. */}
                <div className="flex flex-wrap items-center gap-2">
                    {swatches.map(([key, hex]) => {
                        const selected = form.accent_color === hex;
                        return (
                            <button
                                key={key}
                                type="button"
                                onClick={() => set('accent_color', hex)}
                                title={t(`admin.storeEvents.accents.${key}`, { defaultValue: key })}
                                aria-pressed={selected}
                                aria-label={t(`admin.storeEvents.accents.${key}`, { defaultValue: key })}
                                className={`flex h-8 w-8 items-center justify-center rounded-full border-2 transition ${
                                    selected ? 'border-neutral-900 dark:border-white' : 'border-transparent hover:border-neutral-400'
                                }`}
                                style={{ background: hex || accentPresets.brand }}
                            >
                                {selected && <Check className="h-4 w-4 text-white" />}
                            </button>
                        );
                    })}
                    <input
                        className={`${INPUT} w-32 font-mono`}
                        value={form.accent_color}
                        onChange={(e) => set('accent_color', e.target.value)}
                        placeholder="#1b4e53"
                        dir="ltr"
                        aria-label={t('admin.storeEvents.fields.accent')}
                    />
                </div>
                <span className="mt-1 block text-[11px] text-neutral-500">{t('admin.storeEvents.fields.accentHint')}</span>
            </div>

            <label className="flex items-center gap-2 sm:col-span-2">
                <input
                    type="checkbox"
                    checked={form.is_active}
                    onChange={(e) => set('is_active', e.target.checked)}
                    className="accent-brand-teal h-4 w-4"
                />
                <span className="text-sm text-neutral-700 dark:text-neutral-200">{t('admin.storeEvents.fields.isActive')}</span>
                <span className="text-[11px] text-neutral-500">{t('admin.storeEvents.fields.isActiveHint')}</span>
            </label>
        </div>
    );
}

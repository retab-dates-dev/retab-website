import { Head, router } from '@inertiajs/react';
import { CalendarDays, Plus, Tag } from 'lucide-react';
import { useState } from 'react';

import Button from '@/components/admin/button';
import ConfirmDeleteButton from '@/components/admin/confirm-delete-button';
import Modal from '@/components/admin/modal';
import Pagination from '@/components/admin/pagination';
import StatusBadge from '@/components/admin/status-badge';
import StatusToggle from '@/components/admin/status-toggle';
import { useAdminT } from '@/i18n/use-admin-t';
import AdminLayout from '@/layouts/admin-layout';
import { CARD, THEAD } from '@/lib/admin-ui';

import EventFields, { type EventForm, blankEvent } from './event-fields';

/**
 * Named, time-boxed homepage campaigns ("اليوم الوطني السعودي").
 *
 * 🔑 An event decides what a campaign is CALLED and which products it fronts. It
 * does NOT set prices — that is still each product's own sale window, which already
 * exists and already expires by itself. Keeping the two apart is why an event can
 * be built, paused and thrown away without ever touching the catalogue.
 */

export interface EventRow {
    id: number;
    name_ar: string;
    name_en: string | null;
    subtitle_ar: string | null;
    subtitle_en: string | null;
    starts_at: string;
    ends_at: string;
    accent_color: string | null;
    accent: string;
    is_active: boolean;
    sort_order: number;
    state: 'active' | 'scheduled' | 'ended' | 'paused';
    offer_count: number;
}

interface Paginator<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
}

export default function StoreEventsIndex({ events, accentPresets }: { events: Paginator<EventRow>; accentPresets: Record<string, string> }) {
    const { t } = useAdminT();
    const [creating, setCreating] = useState(false);
    const [form, setForm] = useState<EventForm>(blankEvent);
    const [saving, setSaving] = useState(false);

    const create = () => {
        setSaving(true);
        router.post('/admin/store-events', { ...form }, { onFinish: () => setSaving(false), onSuccess: () => setCreating(false) });
    };

    const openCreate = () => {
        // Reset on OPEN, not on close: a cancelled attempt must not leave its
        // half-typed values sitting in the next one.
        setForm(blankEvent());
        setCreating(true);
    };

    return (
        <AdminLayout>
            <Head title={t('admin.storeEvents.title')} />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{t('admin.storeEvents.title')}</h1>
                    <p className="mt-1 max-w-2xl text-sm text-neutral-500 dark:text-neutral-400">{t('admin.storeEvents.subtitle')}</p>
                </div>
                <Button icon={Plus} onClick={openCreate}>
                    {t('admin.storeEvents.new')}
                </Button>
            </div>

            <div className={`${CARD} overflow-hidden`}>
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className={THEAD}>
                            <tr>
                                <th className="px-4 py-3 text-start font-medium">{t('admin.storeEvents.columns.name')}</th>
                                <th className="px-4 py-3 text-start font-medium">{t('admin.storeEvents.columns.window')}</th>
                                <th className="px-4 py-3 text-start font-medium">{t('admin.storeEvents.columns.offers')}</th>
                                <th className="px-4 py-3 text-start font-medium">{t('admin.storeEvents.columns.status')}</th>
                                <th className="px-4 py-3 text-end font-medium">{t('admin.storeEvents.columns.actions')}</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-neutral-200 dark:divide-neutral-800">
                            {events.data.map((event) => (
                                <tr key={event.id} className="hover:bg-neutral-50 dark:hover:bg-neutral-800/40">
                                    <td className="px-4 py-3">
                                        <div className="flex items-center gap-2.5">
                                            {/* The accent as it will actually render, so a
                                                colour that fights the page is visible here
                                                rather than only on the live homepage. */}
                                            <span className="h-7 w-1.5 shrink-0 rounded-full" style={{ background: event.accent }} aria-hidden />
                                            <div>
                                                <a
                                                    href={`/admin/store-events/${event.id}`}
                                                    className="font-medium text-neutral-900 hover:underline dark:text-neutral-100"
                                                >
                                                    {event.name_ar}
                                                </a>
                                                {event.name_en && <div className="text-xs text-neutral-500">{event.name_en}</div>}
                                            </div>
                                        </div>
                                    </td>
                                    <td className="px-4 py-3 text-neutral-600 tabular-nums dark:text-neutral-300">
                                        <span className="inline-flex items-center gap-1.5">
                                            <CalendarDays className="h-3.5 w-3.5 text-neutral-400" />
                                            {event.starts_at.slice(0, 10)} → {event.ends_at.slice(0, 10)}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 text-neutral-600 tabular-nums dark:text-neutral-300">
                                        <span className="inline-flex items-center gap-1.5">
                                            <Tag className="h-3.5 w-3.5 text-neutral-400" />
                                            {event.offer_count}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3">
                                        <StatusBadge domain="storeEvent" value={event.state} />
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="flex items-center justify-end gap-2">
                                            {/* Pause/resume rather than edit: reversible in one
                                                click, so it needs no confirm step. */}
                                            <StatusToggle
                                                tone={event.is_active ? 'active' : 'stopped'}
                                                label={t(event.is_active ? 'admin.storeEvents.live' : 'admin.storeEvents.paused')}
                                                url={`/admin/store-events/${event.id}/toggle`}
                                            />
                                            <Button variant="secondary" size="sm" href={`/admin/store-events/${event.id}`}>
                                                {t('admin.storeEvents.open')}
                                            </Button>
                                            <ConfirmDeleteButton
                                                itemName={event.name_ar}
                                                onConfirm={() => router.delete(`/admin/store-events/${event.id}`)}
                                                size="sm"
                                            />
                                        </div>
                                    </td>
                                </tr>
                            ))}

                            {events.data.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="px-4 py-12 text-center text-neutral-500">
                                        {t('admin.storeEvents.empty')}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            <Pagination paginator={events} />

            <Modal open={creating} onClose={() => setCreating(false)} title={t('admin.storeEvents.new')} size="lg">
                <EventFields form={form} onChange={setForm} accentPresets={accentPresets} />
                <div className="mt-5 flex justify-end gap-2">
                    <Button variant="secondary" onClick={() => setCreating(false)}>
                        {t('admin.common.cancel')}
                    </Button>
                    <Button onClick={create} disabled={saving || !form.name_ar.trim()}>
                        {t('admin.storeEvents.create')}
                    </Button>
                </div>
            </Modal>
        </AdminLayout>
    );
}

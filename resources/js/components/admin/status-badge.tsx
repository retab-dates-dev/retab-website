import {
    BadgePercent,
    Boxes,
    CalendarClock,
    CalendarX,
    CircleCheck,
    CirclePause,
    CircleSlash,
    CircleX,
    ClipboardCheck,
    CreditCard,
    FilePenLine,
    Handshake,
    Hourglass,
    Lock,
    MessageCircle,
    Package,
    PackageX,
    Pencil,
    Plus,
    Repeat,
    ShoppingBag,
    Trash2,
    TriangleAlert,
    Truck,
    Undo2,
    Zap,
    type LucideIcon,
} from 'lucide-react';

import StatusPill, { type StatusTone } from '@/components/status-pill';
import { useAdminT } from '@/i18n/use-admin-t';

/**
 * ONE registry for every status the admin panel renders.
 *
 * 🔑 This file exists because the tone maps used to live on the pages. Eight pages
 * each picked their own colours, so the same idea ("waiting on us") was amber here,
 * neutral there and blue somewhere else, and the dashboard's recent-orders table
 * rendered order status as a plain grey chip while the orders list rendered it in
 * five colours. Centralising the map is what makes that class of drift impossible:
 * a page now names the DOMAIN and the VALUE, and never chooses an appearance.
 *
 * Tone semantics (and why `attention` is rare) live in `components/status-pill.tsx`.
 * The icon carries the identity so the quiet tones stay distinguishable — a truck
 * against a tick reads faster than indigo against green, and it survives the
 * deliberate flattening of the palette.
 *
 * ⚠️ Adding a value: put it here, not on the page. A value with no entry falls back
 * to `idle` with no icon and still renders its label, so a new backend state degrades
 * to something plain rather than crashing or inventing an urgency it has not earned.
 */

type Entry = { tone: StatusTone; icon?: LucideIcon };

/** i18n key prefix + the value map, per domain. */
const DOMAINS = {
    /** App\Enums\OrderStatus */
    order: {
        prefix: 'status.',
        values: {
            // Waiting on the CUSTOMER's money, not on us — quiet.
            pending_payment: { tone: 'idle', icon: CreditCard },
            // The one order state that owes a staff decision.
            awaiting_confirmation: { tone: 'attention', icon: Hourglass },
            confirmed: { tone: 'active', icon: ClipboardCheck },
            shipped: { tone: 'active', icon: Truck },
            delivered: { tone: 'done', icon: CircleCheck },
            cancelled: { tone: 'stopped', icon: CircleSlash },
            unavailable: { tone: 'stopped', icon: PackageX },
        },
    },
    /** App\Enums\PaymentStatus */
    payment: {
        prefix: 'admin.paymentStatus.',
        values: {
            // A pending payment is a bank transfer someone must verify.
            pending: { tone: 'attention', icon: Hourglass },
            authorized: { tone: 'active', icon: Lock },
            paid: { tone: 'done', icon: CircleCheck },
            // Money back out: neither good nor bad, and nothing left to do.
            refunded: { tone: 'idle', icon: Undo2 },
            partially_refunded: { tone: 'idle', icon: Undo2 },
            voided: { tone: 'idle', icon: CircleSlash },
            failed: { tone: 'stopped', icon: CircleX },
        },
    },
    /** App\Enums\ReturnStatus */
    return: {
        prefix: 'admin.returns.status.',
        values: {
            requested: { tone: 'attention', icon: Hourglass },
            approved: { tone: 'active', icon: ClipboardCheck },
            rejected: { tone: 'stopped', icon: CircleX },
            // ⚠️ `refunded` is `done` here but `idle` under `payment`, deliberately:
            // for a RETURN it is the successful resolution, for a PAYMENT it is just
            // a state the money ended up in.
            exchanged: { tone: 'done', icon: Repeat },
            refunded: { tone: 'done', icon: Undo2 },
        },
    },
    coupon: {
        prefix: 'admin.coupons.status.',
        values: {
            active: { tone: 'active', icon: Zap },
            scheduled: { tone: 'idle', icon: CalendarClock },
            // Expired is not a failure, it is simply over — so not `stopped`.
            expired: { tone: 'idle', icon: CalendarX },
            used_up: { tone: 'idle', icon: CircleSlash },
            inactive: { tone: 'idle', icon: CirclePause },
        },
    },
    discount: {
        prefix: 'admin.discounts.status.',
        values: {
            active: { tone: 'active', icon: Zap },
            scheduled: { tone: 'idle', icon: CalendarClock },
            expired: { tone: 'idle', icon: CalendarX },
        },
    },
    /**
     * A named campaign's lifecycle. Nothing here is `attention`: an event that has
     * not started or is over needs no decision from anyone, and that tone is
     * reserved for the queues in the dashboard's action list.
     */
    storeEvent: {
        prefix: 'admin.storeEvents.status.',
        values: {
            active: { tone: 'active', icon: Zap },
            scheduled: { tone: 'idle', icon: CalendarClock },
            ended: { tone: 'idle', icon: CalendarX },
            // The one state a human chose, so it reads as stopped rather than over.
            paused: { tone: 'stopped', icon: CirclePause },
        },
    },
    changeLog: {
        prefix: 'admin.changeLog.actions.',
        values: {
            created: { tone: 'done', icon: Plus },
            updated: { tone: 'active', icon: Pencil },
            deleted: { tone: 'stopped', icon: Trash2 },
            restored: { tone: 'active', icon: Undo2 },
            // The two bulk tools write here as well (audit-only, they own their undo
            // elsewhere). Both were rendering as untranslated lowercase fallbacks
            // before this registry existed, because the page's map never knew them.
            discount_apply: { tone: 'active', icon: BadgePercent },
            stock_import: { tone: 'active', icon: Boxes },
        },
    },
    /** Meta's approval state, mirrored by hand — nothing here is ours to action. */
    template: {
        prefix: 'admin.marketing.templateStatus.',
        values: {
            approved: { tone: 'done', icon: CircleCheck },
            pending: { tone: 'idle', icon: Hourglass },
            rejected: { tone: 'stopped', icon: CircleX },
            draft: { tone: 'idle', icon: FilePenLine },
        },
    },
    /**
     * Not a status but a category, so tone carries nothing except the one type that
     * genuinely owes a reply. The icons do the telling apart.
     */
    inquiry: {
        prefix: 'admin.contactMessages.types.',
        values: {
            order: { tone: 'idle', icon: ShoppingBag },
            product: { tone: 'idle', icon: Package },
            complaint: { tone: 'attention', icon: TriangleAlert },
            partnership: { tone: 'idle', icon: Handshake },
            other: { tone: 'idle', icon: MessageCircle },
        },
    },
} satisfies Record<string, { prefix: string; values: Record<string, Entry> }>;

export type StatusDomain = keyof typeof DOMAINS;

/** Tone + icon for a value, for callers that need the parts rather than the pill. */
export function statusEntry(domain: StatusDomain, value: string): Entry {
    return (DOMAINS[domain].values as Record<string, Entry>)[value] ?? { tone: 'idle' };
}

export default function StatusBadge({
    domain,
    value,
    label,
    className,
}: {
    domain: StatusDomain;
    value: string;
    /** Overrides the registry's own lookup where a page already resolved the text. */
    label?: string;
    className?: string;
}) {
    const { t } = useAdminT();
    const { tone, icon } = statusEntry(domain, value);

    return (
        <StatusPill tone={tone} icon={icon} className={className}>
            {label ?? t(`${DOMAINS[domain].prefix}${value}`, { defaultValue: value.replace(/_/g, ' ') })}
        </StatusPill>
    );
}

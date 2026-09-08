import { Link } from '@inertiajs/react';
import { type LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';

type Variant = 'primary' | 'secondary' | 'success' | 'danger' | 'warning' | 'ghost';
type Size = 'sm' | 'md';

const BASE =
    'inline-flex items-center justify-center gap-1.5 rounded-lg border font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-60';

const VARIANTS: Record<Variant, string> = {
    primary: 'border-transparent bg-brand-teal text-white hover:bg-brand-teal/90',
    secondary: 'border-neutral-300 text-neutral-700 hover:bg-neutral-100 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800',
    success: 'border-transparent bg-green-600 text-white hover:bg-green-700',
    danger: 'border-red-500/40 bg-red-500/10 text-red-600 hover:border-red-500 hover:bg-red-500/20 dark:text-red-300',
    warning: 'border-amber-500/50 bg-amber-500/10 text-amber-700 hover:border-amber-500 hover:bg-amber-500/20 dark:text-amber-300',
    ghost: 'border-transparent text-neutral-500 hover:bg-neutral-100 hover:text-neutral-900 dark:hover:bg-neutral-800 dark:hover:text-neutral-100',
};

const SIZES: Record<Size, string> = {
    sm: 'px-3 py-1.5 text-xs',
    md: 'px-4 py-2 text-sm',
};

interface Props {
    variant?: Variant;
    size?: Size;
    icon?: LucideIcon;
    className?: string;
    children: ReactNode;
    /** When set, renders an Inertia <Link> instead of a <button>. */
    href?: string;
    method?: 'get' | 'post' | 'put' | 'delete';
    type?: 'button' | 'submit';
    /** id of the <form> to submit. Lets a submit button sit outside its form —
     *  used by the page header so Save can live at the top of a long page. */
    form?: string;
    onClick?: () => void;
    disabled?: boolean;
    /**
     * Accessible name. Required for an ICON-ONLY button: without it a screen
     * reader announces nothing at all, and `getByRole('button', { name })` cannot
     * find it either. Visible text is preferred where there is room for it.
     */
    'aria-label'?: string;
}

/** The one button used across the admin panel. Keeps every action consistent. */
export default function Button({
    variant = 'secondary',
    size = 'md',
    icon: Icon,
    className = '',
    children,
    href,
    method,
    type = 'button',
    form,
    onClick,
    disabled,
    'aria-label': ariaLabel,
}: Props) {
    const cls = `${BASE} ${VARIANTS[variant]} ${SIZES[size]} ${className}`;
    const inner = (
        <>
            {Icon && <Icon className={size === 'sm' ? 'h-3.5 w-3.5' : 'h-4 w-4'} />}
            {children}
        </>
    );

    if (href) {
        return (
            <Link href={href} method={method} className={cls} aria-label={ariaLabel}>
                {inner}
            </Link>
        );
    }

    return (
        <button type={type} form={form} onClick={onClick} disabled={disabled} className={cls} aria-label={ariaLabel}>
            {inner}
        </button>
    );
}

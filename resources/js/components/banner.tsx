import { cn } from 'cn';
import { Info, TriangleAlert, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

type BannerVariant = 'info' | 'warning' | 'danger';

const VARIANT_STYLES: Record<BannerVariant, string> = {
    info: 'border-info/30 bg-info/10',
    warning: 'border-warning/35 bg-warning/10',
    danger: 'border-destructive/35 bg-destructive/10 text-destructive',
};

const VARIANT_ICON_STYLES: Record<BannerVariant, string> = {
    info: 'text-info',
    warning: 'text-warning',
    danger: '',
};

const VARIANT_ICONS: Record<BannerVariant, LucideIcon> = {
    info: Info,
    warning: TriangleAlert,
    danger: TriangleAlert,
};

type BannerProps = {
    variant?: BannerVariant;
    /** Defaults to the variant icon; pass a custom icon (e.g. Lock) or null for none. */
    icon?: LucideIcon | null;
    role?: 'status' | 'alert';
    className?: string;
    children: ReactNode;
} & Omit<React.ComponentProps<'p'>, 'className' | 'children' | 'role'>;

/**
 * Status banner (maquette .banner): soft background, hairline colored border
 * and a leading icon. Announced to assistive technology (role status/alert).
 */
export function Banner({
    variant = 'info',
    icon,
    role = 'status',
    className,
    children,
    ...props
}: BannerProps) {
    const Icon = icon === null ? null : (icon ?? VARIANT_ICONS[variant]);

    return (
        <p
            role={role}
            className={cn(
                'flex items-start gap-2.5 rounded-lg border px-3.5 py-3 text-sm',
                VARIANT_STYLES[variant],
                className,
            )}
            {...props}
        >
            {Icon ? (
                <Icon
                    className={cn('mt-0.5 size-4 shrink-0', VARIANT_ICON_STYLES[variant])}
                    aria-hidden
                />
            ) : null}
            <span className="min-w-0 flex-1">{children}</span>
        </p>
    );
}

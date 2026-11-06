import { usePage } from '@inertiajs/react';

type FlashBannerProps = {
    className?: string;
} & Omit<React.ComponentProps<'p'>, 'className' | 'children'>;

/**
 * Success banner driven by the standard Inertia flash message. Renders
 * nothing while the flash is empty.
 */
export function FlashBanner({ className = '', ...props }: FlashBannerProps) {
    const { flash } = usePage<{ flash?: { status?: string } }>().props;

    if (!flash?.status) {
        return null;
    }

    return (
        <p data-test="flash-banner" className={`mb-4 rounded-lg border bg-muted/50 px-4 py-2.5 text-sm ${className}`} {...props}>
            {flash.status}
        </p>
    );
}

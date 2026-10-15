import { usePage } from '@inertiajs/react';

/**
 * Success banner driven by the standard Inertia flash message. Renders
 * nothing while the flash is empty.
 */
export function FlashBanner({ className = '' }: { className?: string }) {
    const { flash } = usePage<{ flash?: { status?: string } }>().props;

    if (!flash?.status) {
        return null;
    }

    return (
        <p className={`mb-4 rounded-lg border bg-muted/50 px-4 py-2.5 text-sm ${className}`}>
            {flash.status}
        </p>
    );
}

import { cn } from '@/lib/utils';
import type { ReactNode } from 'react';

type FilterChipProps = {
    /** Whether the filter behind this chip is currently active. */
    active: boolean;
    /** Toggles the filter behind this chip. */
    onToggle: () => void;
    /** data-test hook used by the browser suite. */
    testId: string;
    children: ReactNode;
};

/**
 * Toggle chip of the shared filter system (maquette `.filterchip`): an
 * outline pill that fills with the accent soft tone while its filter is
 * active. Filters stay URL-driven, so chips remain shareable.
 */
export function FilterChip({ active, onToggle, testId, children }: FilterChipProps) {
    return (
        <button
            type="button"
            aria-pressed={active}
            data-test={testId}
            onClick={onToggle}
            className={cn(
                'inline-flex h-7.5 items-center gap-1.5 rounded-md border border-border bg-card px-2.5 text-[13px] text-muted-foreground shadow-xs transition-colors hover:bg-secondary hover:text-foreground',
                active && 'border-primary bg-primary/10 text-primary hover:bg-primary/15 hover:text-primary',
            )}
        >
            {children}
        </button>
    );
}

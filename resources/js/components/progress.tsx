import { cn } from 'cn';

export type ProgressColor = 'accent' | 'success' | 'info' | 'destructive' | 'muted' | 'muted-soft';

const FILL_STYLES: Record<ProgressColor, string> = {
    accent: 'bg-primary',
    success: 'bg-success',
    info: 'bg-info',
    destructive: 'bg-destructive',
    muted: 'bg-muted-foreground',
    'muted-soft': 'bg-muted-foreground/50',
};

/**
 * Thin progress bar (maquette .progress): hairline track with a rounded
 * fill. The fill color is a semantic slot, never a raw value.
 */
export function Progress({
    value,
    color = 'accent',
    className,
}: {
    /** Percentage, clamped to 0–100. */
    value: number;
    color?: ProgressColor;
    className?: string;
}) {
    const clamped = Math.max(0, Math.min(100, value));

    return (
        <span className={cn('block h-1.5 overflow-hidden rounded-full bg-secondary', className)}>
            <span
                className={cn('block h-full rounded-full', FILL_STYLES[color])}
                style={{ width: `${clamped}%` }}
            />
        </span>
    );
}

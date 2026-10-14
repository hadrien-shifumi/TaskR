import { Badge } from '@/components/ui/badge';

/**
 * Semantic badges shared by the issue screens. Colors only ever come from the
 * theme tokens (info/success/warning/destructive/secondary) — no raw values.
 */

const DOT = 'size-1.5 shrink-0 rounded-full bg-current';

export function StatusBadge({ name, category }: { name: string; category: string }) {
    const tone =
        category === 'in_progress'
            ? 'bg-info/10 text-info'
            : category === 'done'
              ? 'bg-success/10 text-success'
              : 'bg-secondary/70 text-muted-foreground';

    return (
        <Badge variant="secondary" className={`rounded-full ${tone}`}>
            <span className={DOT} />
            {name}
        </Badge>
    );
}

export function PriorityBadge({ priority }: { priority: string }) {
    if (priority === 'none') {
        return (
            <Badge variant="secondary" className="rounded-full bg-secondary text-muted-foreground">
                none
            </Badge>
        );
    }

    const tone =
        priority === 'urgent'
            ? 'bg-destructive/10 text-destructive'
            : priority === 'high'
              ? 'bg-warning/10 text-warning'
              : 'bg-info/10 text-info';

    return <Badge variant="secondary" className={`rounded-full ${tone}`}>{priority}</Badge>;
}

export function HealthBadge({ status }: { status: string | null }) {
    if (status === null) {
        return (
            <Badge variant="secondary" className="rounded-full bg-secondary text-muted-foreground">
                No projection
            </Badge>
        );
    }

    const tone =
        status === 'ok'
            ? 'bg-success/10 text-success'
            : status === 'at-risk'
              ? 'bg-warning/10 text-warning'
              : 'bg-destructive/10 text-destructive';

    return (
        <Badge variant="secondary" className={`rounded-full ${tone}`}>
            <span className={DOT} />
            {status === 'ok' ? 'On track' : status === 'at-risk' ? 'At risk' : 'Late'}
        </Badge>
    );
}

export function initials(name: string): string {
    const parts = name.trim().split(/\s+/);

    if (parts.length === 1) {
        return parts[0].slice(0, 2).toUpperCase();
    }

    return `${parts[0][0] ?? ''}${parts[parts.length - 1][0] ?? ''}`.toUpperCase();
}

export function Avatar({ name, title }: { name: string; title?: string }) {
    return (
        <span
            title={title ?? name}
            className="grid size-5 shrink-0 place-items-center rounded-full border border-primary/25 bg-primary/20 text-[9px] font-semibold tracking-wide text-primary"
        >
            {initials(name)}
        </span>
    );
}

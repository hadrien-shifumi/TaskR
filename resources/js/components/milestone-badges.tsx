import { Badge } from '@/components/ui/badge';
import { formatDate } from '@/lib/format-date';
import { useTranslation } from 'react-i18next';
import type { MilestoneCard } from '@/types';

/**
 * Milestone state, derived client-side from the card data:
 * closed (completedAt), overdue (server flag, shown first while open),
 * in progress (started), upcoming otherwise.
 */
export type MilestoneState = 'upcoming' | 'inProgress' | 'closed' | 'overdue';

export function milestoneState(milestone: Pick<MilestoneCard, 'startDate' | 'completedAt' | 'overdue'>): MilestoneState {
    if (milestone.completedAt !== null) {
        return 'closed';
    }

    if (milestone.overdue) {
        return 'overdue';
    }

    if (milestone.startDate !== null && milestone.startDate <= todayString()) {
        return 'inProgress';
    }

    return 'upcoming';
}

/** Local `Y-m-d` — comparing with the server's date strings, not timestamps. */
function todayString(): string {
    return new Date().toISOString().slice(0, 10);
}

/** State badge of a milestone row or fiche, colored from the theme tokens. */
export function MilestoneStateBadge({ state }: { state: MilestoneState }) {
    const { t } = useTranslation();

    const tone =
        state === 'overdue'
            ? 'bg-destructive/10 text-destructive'
            : state === 'inProgress'
              ? 'bg-info/10 text-info-strong'
              : state === 'closed'
                ? 'bg-success/10 text-success-strong'
                : 'bg-secondary/70 text-muted-foreground';

    return (
        <Badge variant="secondary" className={`rounded-full ${tone}`} data-test="milestone-state">
            {t(`milestones.state.${state}`)}
        </Badge>
    );
}

/** Date window of a milestone: "start → target", either side optional. */
export function MilestoneWindow({ milestone }: { milestone: Pick<MilestoneCard, 'startDate' | 'targetDate'> }) {
    const { t } = useTranslation();

    if (milestone.startDate === null && milestone.targetDate === null) {
        return <span className="font-mono text-xs text-muted-foreground tabular-nums">{t('milestones.noDates')}</span>;
    }

    return (
        <span data-test="milestone-window" className="font-mono text-xs text-muted-foreground tabular-nums">
            {milestone.startDate !== null && milestone.targetDate !== null
                ? t('milestones.window', { start: formatDate(milestone.startDate), end: formatDate(milestone.targetDate) })
                : formatDate(milestone.startDate ?? milestone.targetDate)}
        </span>
    );
}

/** Flat milestone list rebuilt as an indented tree, depth-first by title. */
export function milestoneTree(milestones: MilestoneCard[]): { milestone: MilestoneCard; depth: number }[] {
    const byParent = new Map<string | null, MilestoneCard[]>();
    for (const milestone of milestones) {
        const key = milestone.parentId;
        byParent.set(key, [...(byParent.get(key) ?? []), milestone]);
    }

    const rows: { milestone: MilestoneCard; depth: number }[] = [];
    const walk = (parentId: string | null, depth: number) => {
        for (const milestone of byParent.get(parentId) ?? []) {
            rows.push({ milestone, depth });
            walk(milestone.id, depth + 1);
        }
    };
    walk(null, 0);

    return rows;
}

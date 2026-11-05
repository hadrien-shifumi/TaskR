import { Badge } from '@/components/ui/badge';
import { useTranslation } from 'react-i18next';

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

export function PriorityBadge({ priority }: { priority: string }) {    const { t } = useTranslation();

    if (priority === 'none') {
        return (
            <Badge variant="secondary" className="rounded-full bg-secondary text-muted-foreground">
                {t('priority.none')}
            </Badge>
        );
    }

    const tone =
        priority === 'urgent'
            ? 'bg-destructive/10 text-destructive'
            : priority === 'high'
              ? 'bg-warning/10 text-warning'
              : 'bg-info/10 text-info';

    return <Badge variant="secondary" className={`rounded-full ${tone}`}>{t(`priority.${priority}`)}</Badge>;
}

/** Blocked/unblockable signal on issue cards: red while at least one blocker
 *  is open, green once every blocker is done or cancelled, nothing when the
 *  issue carries no dependency. */
export function BlockedBadge({ blocked, unblockable }: { blocked: boolean; unblockable: boolean }) {
    const { t } = useTranslation();

    if (!blocked && !unblockable) {
        return null;
    }

    if (blocked) {
        return (
            <Badge variant="destructive" className="rounded-full">
                <span className={DOT} />
                {t('issueBadges.blocked')}
            </Badge>
        );
    }

    return (
        <Badge variant="secondary" className="rounded-full bg-success/10 text-success">
            <span className={DOT} />
            {t('issueBadges.unblockable')}
        </Badge>
    );
}

/** Knowledge-pages signal on the compact cards: « 📖 N » while at least one
 *  page is linked, nothing otherwise. The native title tooltip lists the
 *  linked page titles — no Tooltip component in the UI kit. */
export function KnowledgeBadge({ count, titles }: { count: number; titles: string[] }) {
    const { t } = useTranslation();

    if (count <= 0) {
        return null;
    }

    return (
        <Badge
            variant="secondary"
            className="rounded-full bg-secondary/70 text-muted-foreground"
            title={t('issueBadges.knowledgeTooltip', { titles: titles.join(', ') })}
            data-test="issue-knowledge-badge"
        >
            <span aria-hidden>📖</span>
            {count}
        </Badge>
    );
}

/** Reopened-child signal on done parents' cards: a warning badge while at
 *  least one active child carries a non-terminal category, nothing otherwise.
 *  The front gates on the done category — the raw flag rides the card
 *  payloads, computed server-side without a per-card query. */
export function OpenChildBadge({ hasOpenChild, statusCategory }: { hasOpenChild: boolean; statusCategory: string }) {
    const { t } = useTranslation();

    if (!hasOpenChild || statusCategory !== 'done') {
        return null;
    }

    return (
        <Badge
            variant="secondary"
            className="rounded-full bg-warning/10 text-warning"
            title={t('issueBadges.openChildTooltip')}
            data-test="open-child-badge"
        >
            <span className={DOT} />
            {t('issueBadges.openChild')}
        </Badge>
    );
}

export function HealthBadge({ status }: { status: string | null }) {
    const { t } = useTranslation();

    if (status === null) {
        return (
            <Badge variant="secondary" className="rounded-full bg-secondary text-muted-foreground">
                {t('health.noProjection')}
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
            {status === 'ok' ? t('health.onTrack') : status === 'at-risk' ? t('health.atRisk') : t('health.late')}
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

type StackPerson = {
    id: string | number;
    name: string;
};

/** Overlapping avatar row for assignee lists. Renders nothing when empty. */
export function AvatarStack({ people }: { people: StackPerson[] }) {
    return (
        <span className="flex">
            {people.map((person, index) => (
                <span key={person.id} className={index > 0 ? '-ml-1.75' : ''}>
                    <Avatar name={person.name} />
                </span>
            ))}
        </span>
    );
}

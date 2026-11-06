import { AvatarStack, BlockedBadge, KnowledgeBadge, OpenChildBadge, PriorityBadge, StatusBadge } from '@/components/issue-badges';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { formatDate } from '@/lib/format-date';
import type { IssueRow } from '../types';

/** Mobile card for the filtered issues list: the same signals as the table
 *  row (status, priority, blockers, knowledge pages, assignees, labels, due
 *  date) stacked in a tappable card. Cancelled issues are struck through,
 *  muted and not linked. */
export function IssueCard({ issue }: { issue: IssueRow }) {
    const { t } = useTranslation();
    const cancelled = issue.status.category === 'cancelled';

    return (
        <article
            data-test={`issue-card-${issue.id}`}
            className={cn(
                'flex flex-col gap-2 rounded-xl border bg-card p-3.5',
                cancelled && 'bg-muted/40',
            )}
        >
            <div className="flex flex-wrap items-center gap-1.5">
                <StatusBadge name={issue.status.name} category={issue.status.category} />
                <PriorityBadge priority={issue.priority} />
                <BlockedBadge blocked={issue.blocked} unblockable={issue.unblockable} />
                <OpenChildBadge hasOpenChild={issue.hasOpenChild} statusCategory={issue.status.category} />
                <KnowledgeBadge count={issue.knowledgePagesCount} titles={issue.knowledgePageTitles} />
            </div>
            {cancelled ? (
                <span
                    data-test={`issue-title-${issue.id}`}
                    className="font-medium text-muted-foreground line-through"
                >
                    {issue.title}
                </span>
            ) : (
                <Link
                    data-test={`issue-title-${issue.id}`}
                    href={route('issues.show', issue.id)}
                    className="font-medium hover:text-primary"
                >
                    {issue.title}
                </Link>
            )}
            <span className="font-mono text-xs text-muted-foreground tabular-nums select-all">
                {issue.id.slice(0, 7)} · {issue.project.name}
            </span>
            {issue.labels.length > 0 ? (
                <div className="flex flex-wrap gap-1">
                    {issue.labels.map((l) => (
                        <Badge key={l.id} variant="outline" className="rounded-full text-muted-foreground">
                            {l.name}
                        </Badge>
                    ))}
                </div>
            ) : null}
            <div className="flex items-center justify-between gap-2">
                {issue.assignees.length > 0 ? (
                    <AvatarStack people={issue.assignees} />
                ) : (
                    <span className="text-xs text-muted-foreground">{t('issuesPage.unassigned')}</span>
                )}
                <span
                    className={cn(
                        'font-mono text-xs tabular-nums',
                        issue.overdue ? 'font-medium text-destructive' : 'text-muted-foreground',
                    )}
                >
                    {formatDate(issue.dueDate)}
                </span>
            </div>
        </article>
    );
}

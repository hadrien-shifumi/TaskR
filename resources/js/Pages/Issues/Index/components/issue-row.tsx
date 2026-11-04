import { AvatarStack, BlockedBadge, KnowledgeBadge, PriorityBadge, StatusBadge } from '@/components/issue-badges';
import { Badge } from '@/components/ui/badge';
import { TableCell, TableRow } from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { formatDate } from '@/lib/format-date';
import type { IssueRow } from '../types';

/** Single row of the filtered issues table: the project folds into the
 *  title's meta sub-line, as short ULID · project. Cancelled issues are
 *  struck through, muted and not linked. */
export function IssueTableRow({ issue }: { issue: IssueRow }) {
    const { t } = useTranslation();
    const cancelled = issue.status.category === 'cancelled';

    return (
        <TableRow data-test={`issue-row-${issue.id}`}>
            <TableCell>
                <span className="flex flex-wrap items-center gap-1.5">
                    {cancelled ? (
                        <span
                            data-test={`issue-title-${issue.id}`}
                            className="font-normal text-muted-foreground line-through"
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
                    <BlockedBadge blocked={issue.blocked} unblockable={issue.unblockable} />
                    <KnowledgeBadge count={issue.knowledgePagesCount} titles={issue.knowledgePageTitles} />
                </span>
                <span className="mt-0.5 block font-mono text-xs text-muted-foreground tabular-nums select-all">
                    {issue.id.slice(0, 7)} · {issue.project.name}
                </span>
            </TableCell>
            <TableCell>
                <StatusBadge name={issue.status.name} category={issue.status.category} />
            </TableCell>
            <TableCell>
                <PriorityBadge priority={issue.priority} />
            </TableCell>
            <TableCell className="hidden md:table-cell">
                <div className="flex flex-wrap gap-1">
                    {issue.labels.map((l) => (
                        <Badge key={l.id} variant="outline" className="rounded-full text-muted-foreground">
                            {l.name}
                        </Badge>
                    ))}
                    {issue.labels.length === 0 ? (
                        <span className="text-muted-foreground">—</span>
                    ) : null}
                </div>
            </TableCell>
            <TableCell>
                {issue.assignees.length > 0 ? (
                    <AvatarStack people={issue.assignees} />
                ) : (
                    <span className="text-xs text-muted-foreground">{t('issuesPage.unassigned')}</span>
                )}
            </TableCell>
            <TableCell
                className={cn(
                    'font-mono text-xs tabular-nums',
                    issue.overdue ? 'font-medium text-destructive' : 'text-muted-foreground',
                )}
            >
                {formatDate(issue.dueDate)}
            </TableCell>
        </TableRow>
    );
}

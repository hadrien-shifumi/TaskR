import { AvatarStack, PriorityBadge, StatusBadge } from '@/components/issue-badges';
import { Badge } from '@/components/ui/badge';
import { TableCell, TableRow } from '@/components/ui/table';
import { Link } from '@inertiajs/react';
import type { IssueRow } from '../types';

/** Single row of the filtered issues table. */
export function IssueTableRow({ issue }: { issue: IssueRow }) {
    return (
        <TableRow>
            <TableCell>
                <Link href={route('issues.show', issue.id)} className="font-medium hover:underline">
                    {issue.title}
                </Link>
            </TableCell>
            <TableCell className="text-muted-foreground">{issue.project.name}</TableCell>
            <TableCell>
                <StatusBadge name={issue.status.name} category={issue.status.category} />
            </TableCell>
            <TableCell>
                <PriorityBadge priority={issue.priority} />
            </TableCell>
            <TableCell>
                {issue.assignees.length > 0 ? (
                    <AvatarStack people={issue.assignees} />
                ) : (
                    <span className="text-muted-foreground">—</span>
                )}
            </TableCell>
            <TableCell>
                <div className="flex gap-1">
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
            <TableCell
                className={`font-mono text-xs tabular-nums ${
                    issue.overdue ? 'font-medium text-destructive' : 'text-muted-foreground'
                }`}
            >
                {issue.dueDate ? new Date(issue.dueDate).toLocaleDateString() : '—'}
                {issue.overdue ? ' (overdue)' : ''}
            </TableCell>
        </TableRow>
    );
}

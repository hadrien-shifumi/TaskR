import AppLayout from '@/layouts/app-layout';
import { Avatar, PriorityBadge } from '@/components/issue-badges';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { MoveRight } from 'lucide-react';
import { useState } from 'react';

interface BoardIssue {
    id: string;
    title: string;
    priority: string;
    dueDate: string | null;
    overdue: boolean;
    assignees: { id: number; name: string }[];
}

interface BoardColumn {
    id: string;
    name: string;
    category: string;
    issuesCount: number;
    issues: BoardIssue[];
}

type PageProps = {
    project: { id: string; name: string };
    columns: BoardColumn[];
};

function formatDue(date: string | null): string {
    if (!date) return '';
    return new Date(date).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

export default function Board() {
    const { project, columns } = usePage<PageProps>().props;
    const [draggingId, setDraggingId] = useState<string | null>(null);
    const [overColumn, setOverColumn] = useState<string | null>(null);
    const [moveError, setMoveError] = useState<string | null>(null);

    /**
     * The drop is never optimistic: it calls the existing `issues.move`
     * route and the board syncs on the response. On refusal the card simply
     * never left its column — only the error surfaces.
     */
    const move = (issueId: string, statusId: string) => {
        setMoveError(null);
        router.post(
            route('issues.move', issueId),
            { status_id: statusId },
            {
                onError: (errors) => {
                    setMoveError(errors.status_id ?? 'The move was refused.');
                },
            },
        );
    };

    const drop = (columnId: string) => {
        setOverColumn(null);
        if (draggingId === null) return;
        move(draggingId, columnId);
    };

    return (
        <AppLayout title={project.name}>
            <Head title={`Board — ${project.name}`} />

            <div className="mb-4 flex flex-wrap items-center gap-3">
                <Button variant="ghost" size="sm" onClick={() => router.get(route('projects.index'))}>
                    ← Projects
                </Button>
                <div className="inline-flex items-center gap-0.5 rounded-full border bg-card p-0.5">
                    <Link
                        href={route('projects.health', project.id)}
                        className="inline-flex h-6.5 items-center rounded-full px-3 text-xs font-medium text-muted-foreground hover:bg-accent hover:text-foreground"
                    >
                        Health
                    </Link>
                    <span
                        aria-current="page"
                        className="inline-flex h-6.5 items-center rounded-full bg-secondary px-3 text-xs font-medium"
                    >
                        Kanban
                    </span>
                </div>
                <p className="ml-auto hidden text-sm text-muted-foreground sm:block">
                    Columns follow the workspace statuses. Drag a card to change its status — transitions
                    are always free.
                </p>
                <Button size="sm" onClick={() => router.get(route('issues.index', { project: project.id }))}>
                    New issue
                </Button>
            </div>

            {moveError ? (
                <p
                    role="alert"
                    className="mb-4 rounded-lg border border-destructive/35 bg-destructive/10 px-4 py-2.5 text-sm text-destructive"
                >
                    {moveError}
                </p>
            ) : null}

            <div className="grid grid-flow-col auto-cols-[minmax(264px,1fr)] items-start gap-3.5 overflow-x-auto pb-3">
                {columns.map((column) => (
                    <section
                        key={column.id}
                        data-test={`board-column-${column.category}`}
                        className="flex min-w-0 flex-col rounded-xl border bg-background/70"
                        onDragOver={(e) => {
                            e.preventDefault();
                            setOverColumn(column.id);
                        }}
                        onDragLeave={() => setOverColumn((current) => (current === column.id ? null : current))}
                        onDrop={(e) => {
                            e.preventDefault();
                            drop(column.id);
                        }}
                    >
                        <div className="flex flex-wrap items-center gap-2 px-3.5 pb-2.5 pt-3">
                            <strong className="text-[13px] font-semibold whitespace-nowrap">{column.name}</strong>
                            <Badge variant="secondary" className="rounded-full bg-secondary font-mono text-[11px] text-muted-foreground">
                                {column.issuesCount}
                            </Badge>
                            <div className="ml-auto" />
                        </div>
                        <div
                            className={`flex min-h-24 flex-col gap-2.5 px-3.5 pb-3 pl-3.5 ${
                                overColumn === column.id && draggingId !== null
                                    ? 'rounded-b-xl outline-2 outline-dashed outline-primary/55 outline-offset-[-4px]'
                                    : ''
                            }`}
                        >
                            {column.issues.map((issue) => (
                                <article
                                    key={issue.id}
                                    draggable
                                    data-test={`board-card-${issue.id}`}
                                    onDragStart={(e) => {
                                        e.dataTransfer.setData('text/plain', issue.id);
                                        setDraggingId(issue.id);
                                    }}
                                    onDragEnd={() => setDraggingId(null)}
                                    className={`flex cursor-grab flex-col gap-2 rounded-lg border bg-card px-3 py-2.75 shadow-xs ${
                                        draggingId === issue.id ? 'opacity-35' : ''
                                    }`}
                                >
                                    <Link
                                        href={route('issues.show', issue.id)}
                                        className={`text-[13.5px] leading-snug font-medium hover:text-primary ${
                                            column.category === 'cancelled'
                                                ? 'font-normal text-muted-foreground line-through'
                                                : ''
                                        }`}
                                    >
                                        {issue.title}
                                    </Link>
                                    <div className="flex min-h-5.5 flex-wrap items-center gap-2">
                                        <PriorityBadge priority={issue.priority} />
                                        <div className="ml-auto" />
                                        <DropdownMenu>
                                            <DropdownMenuTrigger
                                                render={
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        aria-label={`Move "${issue.title}" to another status`}
                                                        className="h-6 gap-1 px-1.5 text-[11px]"
                                                    >
                                                        <MoveRight className="size-3" />
                                                        Move to
                                                    </Button>
                                                }
                                            />
                                            <DropdownMenuContent align="end">
                                                <DropdownMenuLabel>Move to</DropdownMenuLabel>
                                                <DropdownMenuSeparator />
                                                {columns
                                                    .filter((candidate) => candidate.id !== column.id)
                                                    .map((candidate) => (
                                                        <DropdownMenuItem
                                                            key={candidate.id}
                                                            onClick={() => move(issue.id, candidate.id)}
                                                        >
                                                            {candidate.name}
                                                        </DropdownMenuItem>
                                                    ))}
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                        {issue.dueDate ? (
                                            <span
                                                className={`font-mono text-[11px] tabular-nums ${
                                                    issue.overdue ? 'font-medium text-destructive' : 'text-muted-foreground'
                                                }`}
                                            >
                                                {formatDue(issue.dueDate)}
                                            </span>
                                        ) : null}
                                        <span className="flex">
                                            {issue.assignees.map((assignee, index) => (
                                                <span
                                                    key={assignee.id}
                                                    className={index > 0 ? '-ml-1.75' : ''}
                                                >
                                                    <Avatar name={assignee.name} />
                                                </span>
                                            ))}
                                        </span>
                                    </div>
                                </article>
                            ))}
                            {column.issues.length === 0 ? (
                                <p className="rounded-lg border border-dashed px-2.5 py-5 text-center text-xs text-muted-foreground">
                                    No issue — drop a card here
                                </p>
                            ) : null}
                        </div>
                    </section>
                ))}
            </div>

            <p className="mt-2 text-sm text-muted-foreground">
                Card order within a column is not an identifier — issues are keyed by their opaque ULID.
            </p>
        </AppLayout>
    );
}

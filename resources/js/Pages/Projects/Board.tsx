import { ProjectMenu } from '@/components/project-menu';
import { ProjectViewSwitcher } from '@/components/project-view-switcher';
import { AvatarStack, HealthBadge, PriorityBadge, StatusBadge } from '@/components/issue-badges';
import { Banner } from '@/components/banner';
import { FlashBanner } from '@/components/flash-banner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, MoveRight, Plus } from 'lucide-react';
import { useState } from 'react';
import type { HealthData, LabelRef, UserRef } from '@/types';
import type { TemplateOption } from '@/Pages/Issues/Index/types';
import { BoardCreateIssueDialog } from './Board/create-issue-dialog';

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
    project: {
        id: string;
        name: string;
        description: string | null;
        targetDate: string | null;
    };
    health: HealthData;
    columns: BoardColumn[];
    parentsOnly: boolean;
    /**
     * Creation-dialog data, fed by the board payload. Optional for now: the
     * matching dialog sections stay hidden until the payload ships them.
     */
    labels?: LabelRef[];
    templates?: TemplateOption[];
    members?: UserRef[];
};

function formatDue(date: string | null): string {
    if (!date) return '';
    return new Date(date).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

function formatDate(date: string | null): string {
    if (!date) return 'no target date';
    return new Date(date).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

export default function Board() {
    const { project, health, columns, parentsOnly, labels, templates, members } = usePage<PageProps>().props;
    const [draggingId, setDraggingId] = useState<string | null>(null);
    const [overColumn, setOverColumn] = useState<string | null>(null);
    const [moveError, setMoveError] = useState<string | null>(null);
    const [creating, setCreating] = useState(false);
    /** Status preselected in the creation dialog (column "+" button). */
    const [createDefaultStatusId, setCreateDefaultStatusId] = useState<string | null>(null);

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
        <AppLayout
            leading={
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label="Back to projects"
                    onClick={() => router.get(route('projects.index'))}
                >
                    <ArrowLeft aria-hidden />
                </Button>
            }
            title={
                <>
                    <span>{project.name}</span>
                    <HealthBadge status={health.status} />
                </>
            }
            actions={
                <>
                    <span className="hidden font-mono text-xs text-muted-foreground tabular-nums sm:inline">
                        {health.totalIssues} issues · target: {formatDate(project.targetDate)}
                        {health.status === 'late' ? ' · overdue' : ''}
                    </span>
                    <ProjectViewSwitcher projectId={project.id} active="board" />
                    <Button
                        variant={parentsOnly ? 'default' : 'outline'}
                        size="sm"
                        onClick={() =>
                            router.get(
                                route('projects.board', project.id),
                                parentsOnly ? {} : { parents: '1' },
                                { preserveState: true },
                            )
                        }
                    >
                        Parents only
                    </Button>
                    <ProjectMenu project={project} issuesCount={health.totalIssues} />
                    <Button
                        size="sm"
                        onClick={() => {
                            setCreateDefaultStatusId(null);
                            setCreating(true);
                        }}
                    >
                        <Plus className="size-3.5" aria-hidden />
                        New issue
                    </Button>
                </>
            }
        >
            <Head title={`Board — ${project.name}`} />

            <p className="mb-4 text-sm text-muted-foreground">
                {parentsOnly
                    ? 'Macro view — subtasks are hidden. Columns follow the workspace statuses: drag a card to change its status, transitions are always free.'
                    : 'Columns follow the workspace statuses, in their order. Drag a card to change its status — transitions are always free.'}
            </p>

            <FlashBanner />

            {moveError ? (
                <Banner variant="danger" role="alert" icon={null} className="mb-4 px-4 py-2.5">
                    {moveError}
                </Banner>
            ) : null}

            <div className="grid grid-flow-col auto-cols-[minmax(264px,1fr)] items-start gap-3.5 overflow-x-auto pb-3">
                {columns.map((column) => (
                    <section
                        key={column.id}
                        data-test={`board-column-${column.category}`}
                        className="flex min-w-0 flex-col rounded-xl border bg-card/30"
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
                            <StatusBadge name={column.category} category={column.category} />
                            <Badge
                                variant="secondary"
                                className="rounded-full bg-secondary font-mono text-[11px] text-muted-foreground"
                            >
                                {column.issuesCount}
                            </Badge>
                            <div className="ml-auto" />
                            <Button
                                variant="ghost"
                                size="icon"
                                data-test={`board-column-add-${column.category}`}
                                aria-label={`Add an issue in ${column.name}`}
                                onClick={() => {
                                    setCreateDefaultStatusId(column.id);
                                    setCreating(true);
                                }}
                            >
                                <Plus className="size-3.5" aria-hidden />
                            </Button>
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
                                    className={`flex cursor-grab flex-col gap-2 rounded-lg border bg-card px-3 py-2.75 shadow-xs hover:border-primary/40 ${
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
                                    <div
                                        className="flex min-h-5.5 flex-wrap items-center gap-2"
                                        onDragStart={(e) => e.stopPropagation()}
                                    >
                                        <StatusBadge name={column.name} category={column.category} />
                                        <PriorityBadge priority={issue.priority} />
                                        <div className="ml-auto" />
                                        {issue.dueDate ? (
                                            <span
                                                className={`font-mono text-[11px] tabular-nums ${
                                                    issue.overdue
                                                        ? 'font-medium text-destructive'
                                                        : 'text-muted-foreground'
                                                }`}
                                            >
                                                {formatDue(issue.dueDate)}
                                            </span>
                                        ) : null}
                                        <span className="flex">
                                            <AvatarStack people={issue.assignees} />
                                        </span>
                                        <DropdownMenu>
                                            <DropdownMenuTrigger
                                                aria-label={`Move "${issue.title}" to another status`}
                                                className="inline-flex h-6 cursor-pointer items-center gap-1 rounded-md px-1.5 font-mono text-[11px] text-muted-foreground outline-none hover:bg-accent hover:text-foreground"
                                            >
                                                <MoveRight className="size-3" />
                                                Move to
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                <DropdownMenuGroup>
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
                                                </DropdownMenuGroup>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
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

            <BoardCreateIssueDialog
                key={createDefaultStatusId ?? 'new'}
                open={creating}
                onClose={() => setCreating(false)}
                project={{ id: project.id, name: project.name }}
                statuses={columns.map((column) => ({
                    id: column.id,
                    name: column.name,
                    category: column.category,
                }))}
                labels={labels ?? []}
                templates={templates ?? []}
                members={members ?? []}
                defaultStatusId={createDefaultStatusId}
            />
        </AppLayout>
    );
}

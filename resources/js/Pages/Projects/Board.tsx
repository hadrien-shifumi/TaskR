import { ProjectMenu } from '@/components/project-menu';
import { ProjectViewSwitcher } from '@/components/project-view-switcher';
import { Avatar, AvatarStack, BlockedBadge, HealthBadge, KnowledgeBadge, OpenChildBadge, PriorityBadge, StatusBadge } from '@/components/issue-badges';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Archive, ArrowLeft, MoveRight, Plus, User } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { CascadeMoveDialog, pendingDescendantsCount } from '@/components/cascade-move-dialog';
import type { DescendantsSummary, HealthData, LabelRef, MilestoneOption, UserRef } from '@/types';
import type { TemplateOption } from '@/Pages/Issues/Index/types';
import { formatShortDate, formatDate } from '@/lib/format-date';
import { BoardCreateIssueDialog } from './Board/create-issue-dialog';
import { ArchiveCompletedDialog } from './Board/archive-completed-dialog';
import { boardIssueUrl, IssueDetailDialog, type IssueDetailPayload } from './Board/issue-detail-dialog';

interface BoardIssue {
    id: string;
    title: string;
    priority: string;
    dueDate: string | null;
    overdue: boolean;
    blocked: boolean;
    unblockable: boolean;
    hasOpenChild: boolean;
    descendants: DescendantsSummary;
    assignees: { id: number; name: string }[];
    knowledgePagesCount: number;
    knowledgePageTitles: string[];
}

interface BoardColumn {
    id: string;
    name: string;
    category: string;
    issuesCount: number;
    issues: BoardIssue[];
}

interface ProjectPerson {
    id: number;
    name: string;
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
    filters: Record<string, string>;
    projectPeople: ProjectPerson[];
    currentUserId: number | null;
    /**
     * Creation-dialog data, fed by the board payload. Optional for now: the
     * matching dialog sections stay hidden until the payload ships them.
     */
    labels?: LabelRef[];
    templates?: TemplateOption[];
    members?: UserRef[];
    /** Milestones of the project, offered by the creation dialog. */
    milestones?: MilestoneOption[];
    /** Present when the URL carries an `?issue=` param: the modal is open. */
    issueDetail?: IssueDetailPayload | null;
    /** Shared by HandleInertiaRequests — drives the owner-only archive button. */
    workspace?: { role?: string } | null;
};

export default function Board() {
    const {
        project,
        health,
        columns,
        parentsOnly,
        filters,
        projectPeople,
        currentUserId,
        labels,
        templates,
        members,
        milestones,
        issueDetail,
        workspace,
    } = usePage<PageProps>().props;
    const { t } = useTranslation();
    const [draggingId, setDraggingId] = useState<string | null>(null);
    const [overColumn, setOverColumn] = useState<string | null>(null);
    const [moveError, setMoveError] = useState<string | null>(null);
    const [creating, setCreating] = useState(false);
    /** Status preselected in the creation dialog (column "+" button). */
    const [createDefaultStatusId, setCreateDefaultStatusId] = useState<string | null>(null);
    const [archivingCompleted, setArchivingCompleted] = useState(false);
    /** A parent move waiting for the sub-tree answer. */
    const [pendingMove, setPendingMove] = useState<{ issueId: string; statusId: string; count: number } | null>(
        null,
    );

    const completedCounts = columns.reduce(
        (counts, column) => {
            if (column.category === 'done' || column.category === 'cancelled') {
                counts[column.category] += column.issues.length;
            }

            return counts;
        },
        { done: 0, cancelled: 0 },
    );
    const completedTotal = completedCounts.done + completedCounts.cancelled;
    const canArchiveCompleted = workspace?.role === 'owner' && completedTotal > 0;

    /** Sets the assignee filter via URL query param (preserveState). */
    const setAssigneeFilter = (value: string) => {
        const params: Record<string, string> = { ...filters };
        if (value === '' || value === 'all') {
            delete params.assignee;
        } else {
            params.assignee = value;
        }
        if (parentsOnly) {
            params.parents = '1';
        }
        router.get(route('projects.board', project.id), params, { preserveState: true });
    };

    const currentAssignee = filters.assignee ?? '';

    /**
     * The drop is never optimistic: it calls the existing `issues.move`
     * route and the board syncs on the response. On refusal the card simply
     * never left its column — only the error surfaces.
     */
    const move = (issueId: string, statusId: string, cascade = false) => {
        setMoveError(null);
        router.post(
            route('issues.move', issueId),
            { status_id: statusId, cascade_children: cascade },
            {
                onError: (errors) => {
                    setMoveError(errors.status_id ?? t('projectBoard.moveRefused'));
                },
                onHttpException: () => {
                    setMoveError(t('projectBoard.moveRefused'));
                    return false;
                },
            },
        );
    };

    // A parent with sub-tickets in another status asks first: the dialog
    // offers to bring the whole active sub-tree along. The server enforces
    // the rule either way — a refusal surfaces in the banner.
    const requestMove = (issue: BoardIssue, statusId: string) => {
        const count = pendingDescendantsCount(issue.descendants, statusId);
        if (count > 0) {
            setPendingMove({ issueId: issue.id, statusId, count });
        } else {
            move(issue.id, statusId);
        }
    };

    const confirmMove = (cascade: boolean) => {
        const pending = pendingMove;
        setPendingMove(null);
        if (pending) move(pending.issueId, pending.statusId, cascade);
    };

    const drop = (columnId: string) => {
        setOverColumn(null);
        if (draggingId === null) return;
        const dragged = columns.flatMap((column) => column.issues).find((issue) => issue.id === draggingId);
        if (dragged) {
            requestMove(dragged, columnId);
        }
        setDraggingId(null);
    };

    return (
        <AppLayout
            leading={
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label={t('projectBoard.backToProjects')}
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
                        {t('projectBoard.issuesTarget', {
                            count: health.totalIssues,
                            date: project.targetDate ? formatDate(project.targetDate) : t('home.noTargetDate'),
                        })}
                        {health.status === 'late' ? t('projectBoard.overdueSuffix') : ''}
                    </span>
                    <ProjectViewSwitcher projectId={project.id} active="board" />
                    <ProjectMenu project={project} issuesCount={health.totalIssues} />
                    <Button
                        size="sm"
                        onClick={() => {
                            setCreateDefaultStatusId(null);
                            setCreating(true);
                        }}
                    >
                        <Plus className="size-3.5" aria-hidden />
                        {t('issues.newIssue')}
                    </Button>
                </>
            }
        >
            <Head title={t('projectBoard.headTitle', { name: project.name })} />

            <p className="mb-4 text-sm text-muted-foreground">
                {parentsOnly
                    ? t('projectBoard.macroIntro')
                    : t('projectBoard.intro')}
            </p>

            <FlashBanner />

            <div className="mb-3.5 flex flex-wrap items-center gap-2" data-test="board-toolbar">
                <Select
                    value={currentAssignee || 'all'}
                    onValueChange={(value) => setAssigneeFilter(value ?? '')}
                >
                    <SelectTrigger
                        className="h-7.5 w-40 text-[13px]"
                        aria-label={t('projectBoard.filterByAssignee')}
                        data-test="board-assignee-filter"
                    >
                        <User className="size-3.5 text-muted-foreground" aria-hidden />
                        <SelectValue placeholder={t('projectBoard.allAssignees')} />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">{t('projectBoard.allAssignees')}</SelectItem>
                        {currentUserId !== null ? (
                            <SelectItem value={String(currentUserId)} data-test="board-filter-me">
                                {t('projectBoard.me')}
                            </SelectItem>
                        ) : null}
                        {projectPeople
                            .filter((person) => person.id !== currentUserId)
                            .map((person) => (
                                <SelectItem key={person.id} value={String(person.id)}>
                                    <span className="flex items-center gap-2">
                                        <Avatar name={person.name} className="size-4" />
                                        {person.name}
                                    </span>
                                </SelectItem>
                            ))}
                    </SelectContent>
                </Select>
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
                    {t('projectBoard.parentsOnly')}
                </Button>
                {canArchiveCompleted ? (
                    <Button
                        variant="outline"
                        size="sm"
                        data-test="board-archive-completed"
                        onClick={() => setArchivingCompleted(true)}
                    >
                        <Archive className="size-3.5" aria-hidden />
                        {t('projectBoard.archiveCompleted')}
                    </Button>
                ) : null}
            </div>

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
                                aria-label={t('projectBoard.addInColumn', { name: column.name })}
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
                                        href={boardIssueUrl(project.id, filters, parentsOnly, issue.id)}
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
                                        <BlockedBadge blocked={issue.blocked} unblockable={issue.unblockable} />
                                        <OpenChildBadge hasOpenChild={issue.hasOpenChild} statusCategory={column.category} />
                                        <KnowledgeBadge count={issue.knowledgePagesCount} titles={issue.knowledgePageTitles} />
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
                                                {formatShortDate(issue.dueDate)}
                                            </span>
                                        ) : null}
                                        <span className="flex">
                                            <AvatarStack people={issue.assignees} />
                                        </span>
                                        <DropdownMenu>
                                            <DropdownMenuTrigger
                                                aria-label={t('projectBoard.moveToAria', { title: issue.title })}
                                                className="inline-flex h-6 cursor-pointer items-center gap-1 rounded-md px-1.5 font-mono text-[11px] text-muted-foreground outline-none hover:bg-accent hover:text-foreground"
                                            >
                                                <MoveRight className="size-3" />
                                                {t('projectBoard.moveTo')}
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                <DropdownMenuGroup>
                                                    <DropdownMenuLabel>{t('projectBoard.moveTo')}</DropdownMenuLabel>
                                                    <DropdownMenuSeparator />
                                                    {columns
                                                        .filter((candidate) => candidate.id !== column.id)
                                                        .map((candidate) => (
                                                            <DropdownMenuItem
                                                                key={candidate.id}
                                                                onClick={() => requestMove(issue, candidate.id)}
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
                                    {t('projectBoard.emptyColumn')}
                                </p>
                            ) : null}
                        </div>
                    </section>
                ))}
            </div>

            <p className="mt-2 text-sm text-muted-foreground">
                {t('projectBoard.orderNote')}
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
                milestones={milestones ?? []}
                defaultStatusId={createDefaultStatusId}
            />
            <ArchiveCompletedDialog
                projectId={project.id}
                open={archivingCompleted}
                doneCount={completedCounts.done}
                cancelledCount={completedCounts.cancelled}
                onClose={() => setArchivingCompleted(false)}
            />

            {pendingMove ? (
                <CascadeMoveDialog
                    count={pendingMove.count}
                    targetStatusName={columns.find((column) => column.id === pendingMove.statusId)?.name ?? ''}
                    onConfirm={confirmMove}
                    onClose={() => setPendingMove(null)}
                />
            ) : null}

            {issueDetail ? (
                <IssueDetailDialog
                    projectId={project.id}
                    filters={filters}
                    parentsOnly={parentsOnly}
                    detail={issueDetail}
                />
            ) : null}
        </AppLayout>
    );
}

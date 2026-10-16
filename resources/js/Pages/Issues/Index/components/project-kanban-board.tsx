import { AvatarStack, PriorityBadge, StatusBadge } from '@/components/issue-badges';
import { Banner } from '@/components/banner';
import { Badge } from '@/components/ui/badge';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Link, router } from '@inertiajs/react';
import { MoveRight } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { formatShortDate } from '@/lib/format-date';
import type { ProjectBoard } from '../types';

type ProjectKanbanBoardProps = {
    board: ProjectBoard;
};

/**
 * Kanban board for a single project, embedded in the /issues kanban view.
 * Drag & drop uses the existing `issues.move` route — no optimistic updates.
 */
export function ProjectKanbanBoard({ board }: ProjectKanbanBoardProps) {
    const { t } = useTranslation();
    const [draggingId, setDraggingId] = useState<string | null>(null);
    const [overColumn, setOverColumn] = useState<string | null>(null);
    const [moveError, setMoveError] = useState<string | null>(null);

    const move = (issueId: string, statusId: string) => {
        setMoveError(null);
        router.post(
            route('issues.move', issueId),
            { status_id: statusId },
            {
                preserveState: true,
                onError: (errors) => {
                    setMoveError(errors.status_id ?? t('issuesPage.kanban.moveRefused'));
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
        <section className="mb-6" data-test={`project-board-${board.project.id}`}>
            <h2 className="mb-2.5 text-sm font-semibold">{board.project.name}</h2>

            {moveError ? (
                <Banner variant="danger" role="alert" icon={null} className="mb-2 px-4 py-2.5">
                    {moveError}
                </Banner>
            ) : null}

            <div className="grid grid-flow-col auto-cols-[minmax(264px,1fr)] items-start gap-3.5 overflow-x-auto pb-3">
                {board.columns.map((column) => (
                    <section
                        key={column.id}
                        data-test={`kanban-column-${board.project.id}-${column.category}`}
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
                                    data-test={`kanban-card-${issue.id}`}
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
                                                aria-label={t('issuesPage.kanban.moveToAria', { title: issue.title })}
                                                className="inline-flex h-6 cursor-pointer items-center gap-1 rounded-md px-1.5 font-mono text-[11px] text-muted-foreground outline-none hover:bg-accent hover:text-foreground"
                                            >
                                                <MoveRight className="size-3" />
                                                {t('issuesPage.kanban.moveTo')}
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                <DropdownMenuGroup>
                                                    <DropdownMenuLabel>{t('issuesPage.kanban.moveTo')}</DropdownMenuLabel>
                                                    <DropdownMenuSeparator />
                                                    {board.columns
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
                                    {t('issuesPage.kanban.emptyColumn')}
                                </p>
                            ) : null}
                        </div>
                    </section>
                ))}
            </div>
        </section>
    );
}

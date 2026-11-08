import { Avatar, StatusBadge } from '@/components/issue-badges';
import { PRIORITIES } from '@/components/priority-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Link, router, useForm } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/utils';
import { CascadeMoveDialog, pendingDescendantsCount } from '@/components/cascade-move-dialog';
import type { LabelRef, MilestoneOption, UserRef } from '@/types';
import type { BlocksRef, IssueDetail } from '../types';
import { EditDatesDialog } from './edit-dates-dialog';
import { formatDate, formatHours, overdueDays } from './format';
import { KnowledgeProjectBadge, LinkKnowledgeDialog } from './link-knowledge-dialog';
import { SetDependenciesDialog } from './set-dependencies-dialog';
import { SetMilestoneDialog } from './set-milestone-dialog';
import { SetParentDialog } from './set-parent-dialog';

type IssueSidebarProps = {
    issue: IssueDetail;
    statuses: { id: string; name: string; category: string }[];
    members: UserRef[];
    labels: LabelRef[];
    candidates: { id: string; title: string }[];
    dependencyCandidates: { id: string; title: string }[];
    /** Milestones of the issue's project, offered by the milestone dialog. */
    milestoneOptions: MilestoneOption[];
    /** May the user write the project's content (milestones included)? */
    canContribute: boolean;
    /** Nameable projects of the workspace, for the knowledge pages' badges. */
    projects: { id: string; name: string }[];
    /** Builds the parent link href — lets the board modal route in place. */
    buildIssueHref?: (issueId: string) => string;
    /**
     * Pins the card below the page header while the fiche scrolls (full
     * page default). The board modal disables it so the card scrolls away
     * with the modal's single scrolling body.
     */
    sticky?: boolean;
};

type PropRowProps = {
    label: string;
    /** Small muted action pinned to the right end of the label row. */
    action?: ReactNode;
    hint?: string;
    children: ReactNode;
};

/** Maquette .prop: mono label row, value, hint — rows split by hairlines. */
function PropRow({ label, action, hint, children }: PropRowProps) {
    return (
        <div className="flex flex-col gap-1.5 border-t border-border py-3 first:border-t-0 first:pt-0 last:pb-0">
            <div className="flex items-center justify-between font-mono text-xs text-muted-foreground">
                <span>{label}</span>
                {action}
            </div>
            {children}
            {hint ? <span className="text-xs text-muted-foreground">{hint}</span> : null}
        </div>
    );
}

/** Maquette .prop-label button: small muted action inside the label row. */
function PropAction({ children, ...props }: React.ComponentProps<'button'>) {
    return (
        <button
            type="button"
            className="rounded px-1 font-mono text-xs text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
            {...props}
        >
            {children}
        </button>
    );
}

/**
 * Maquette issue-properties: sticky card with the prop list — status,
 * priority, assignees, labels, due date, estimation, milestone, parent,
 * dependencies, knowledge pages.
 */
export function IssueSidebar({
    issue,
    statuses,
    members,
    labels,
    candidates,
    dependencyCandidates,
    milestoneOptions,
    canContribute,
    projects,
    buildIssueHref,
    sticky = true,
}: IssueSidebarProps) {
    const { t } = useTranslation();
    const [datesOpen, setDatesOpen] = useState(false);
    const [parentOpen, setParentOpen] = useState(false);
    const [milestoneOpen, setMilestoneOpen] = useState(false);
    const [dependenciesOpen, setDependenciesOpen] = useState(false);
    const [knowledgeOpen, setKnowledgeOpen] = useState(false);
    const [addingLabel, setAddingLabel] = useState(false);
    /** A parent move waiting for the sub-tree answer: {statusId, count}. */
    const [pendingMove, setPendingMove] = useState<{ statusId: string; count: number } | null>(null);
    const [moveError, setMoveError] = useState<string | null>(null);

    const issueHref = (issueId: string) => buildIssueHref?.(issueId) ?? route('issues.show', issueId);

    const move = (statusId: string, cascade = false) => {
        setMoveError(null);
        router.post(
            route('issues.move', issue.id),
            { status_id: statusId, cascade_children: cascade },
            {
                onError: (errors) => setMoveError(errors.status_id ?? t('issueShow.moveRefused')),
                onHttpException: () => {
                    setMoveError(t('issueShow.moveRefused'));
                    return false;
                },
            },
        );
    };

    // A parent with sub-tickets in another status asks first: the dialog
    // offers to bring the whole active sub-tree along. The server enforces
    // the rule either way — a refusal surfaces right here.
    const requestMove = (statusId: string) => {
        if (statusId === issue.status.id) return;

        const count = pendingDescendantsCount(issue.descendants, statusId);
        if (count > 0) {
            setPendingMove({ statusId, count });
        } else {
            move(statusId);
        }
    };

    const confirmMove = (cascade: boolean) => {
        const pending = pendingMove;
        setPendingMove(null);
        if (pending) move(pending.statusId, cascade);
    };

    // The update action requires the title and rewrites every field it
    // knows: priority changes carry the untouched fields along.
    const changePriority = (priority: string) => {
        router.patch(route('issues.update', issue.id), {
            title: issue.title,
            description: issue.description ?? '',
            priority,
            due_date: issue.dueDate ? issue.dueDate.slice(0, 10) : '',
        });
    };

    const toggleAssignee = (userId: string) => {
        const current = issue.assignees.map((assignee) => String(assignee.id));
        const next = current.includes(String(userId))
            ? current.filter((id) => id !== String(userId))
            : [...current, String(userId)];
        router.post(route('issues.assignees', issue.id), { assignees: next });
    };

    const toggleAutoCompletion = (enabled: boolean) => {
        router.post(route('issues.auto-complete', issue.id), { enabled });
    };

    const toggleLabel = (labelId: string) => {
        const current = issue.labels.map((label) => label.id);
        const next = current.includes(labelId)
            ? current.filter((id) => id !== labelId)
            : [...current, labelId];
        router.post(route('issues.labels', issue.id), { label_ids: next });
    };

    // Removing a blocker re-syncs this issue's blocker list without it.
    const removeBlocker = (blockerId: string) => {
        router.post(route('issues.dependencies', issue.id), {
            blocked_by: issue.blockedBy
                .map((blocker) => blocker.id)
                .filter((id) => id !== blockerId),
        });
    };

    // The mirror side deletes from the other end: the edge lives on the
    // dependent, so the sync POST goes there with its list minus this issue.
    const stopBlocking = (dependent: BlocksRef) => {
        router.post(route('issues.dependencies', dependent.id), {
            blocked_by: dependent.blockerIds.filter((id) => id !== issue.id),
        });
    };

    // Labels are free-form: an existing name attaches directly, a new one is
    // created on the fly then attached.
    const labelForm = useForm({ name: '' });

    const submitLabel = (e: React.FormEvent) => {
        e.preventDefault();
        const name = labelForm.data.name.trim();
        if (!name) return;

        const attach = (labelId: string) => {
            const current = issue.labels.map((label) => label.id);
            if (!current.includes(labelId)) {
                router.post(route('issues.labels', issue.id), { label_ids: [...current, labelId] });
            }
        };

        const existing = labels.find((label) => label.name.toLowerCase() === name.toLowerCase());
        if (existing) {
            attach(existing.id);
            labelForm.reset();
            setAddingLabel(false);
            return;
        }

        labelForm.post(route('labels.store'), {
            onSuccess: (page) => {
                const created = (page.props.labels as LabelRef[] | undefined)?.find(
                    (label) => label.name === name,
                );
                if (created) attach(created.id);
                labelForm.reset();
                setAddingLabel(false);
            },
        });
    };

    const assignedIds = issue.assignees.map((assignee) => String(assignee.id));
    const unassigned = members.filter((member) => !assignedIds.includes(String(member.id)));

    const assigneeMenuContent = (
        <DropdownMenuContent align="start">
            {unassigned.length > 0 ? (
                unassigned.map((member) => (
                    <DropdownMenuItem key={member.id} onClick={() => toggleAssignee(member.id)}>
                        {member.name}
                    </DropdownMenuItem>
                ))
            ) : (
                <DropdownMenuItem disabled>{t('issueShow.allAssigned')}</DropdownMenuItem>
            )}
        </DropdownMenuContent>
    );

    const daysLate = issue.dueDate ? overdueDays(issue.dueDate) : 0;

    return (
        <aside data-test="issue-sidebar" className={cn(sticky && 'lg:sticky lg:top-[76px]')}>
            <Card className="py-5">
                <CardContent className="flex flex-col px-5">
                    <PropRow label={t('issues.fields.status')} hint={t('issueShow.statusHint')}>
                        <Select value={issue.status.id} onValueChange={(value) => value && requestMove(value)}>
                            <SelectTrigger
                                data-test="status-select"
                                className="w-full"
                                aria-label={t('issueShow.statusAria')}
                            >
                                <SelectValue>{issue.status.name}</SelectValue>
                            </SelectTrigger>
                            <SelectContent>
                                {statuses.map((status) => (
                                    <SelectItem key={status.id} value={status.id}>
                                        {status.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {moveError ? (
                            <p role="alert" data-test="move-error" className="text-sm text-destructive">
                                {moveError}
                            </p>
                        ) : null}
                    </PropRow>

                    <PropRow label={t('issues.fields.priority')}>
                        <Select
                            value={issue.priority}
                            onValueChange={(value) => value && changePriority(value)}
                        >
                            <SelectTrigger
                                data-test="priority-select"
                                className="w-full"
                                aria-label={t('issues.fields.priority')}
                            >
                                <SelectValue>{t(`priority.${issue.priority}`)}</SelectValue>
                            </SelectTrigger>
                            <SelectContent>
                                {PRIORITIES.map((priority) => (
                                    <SelectItem key={priority} value={priority}>
                                        {t(`priority.${priority}`)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </PropRow>

                    <PropRow
                        label={t('columns.assignees')}
                        action={
                            <DropdownMenu>
                                <DropdownMenuTrigger
                                    aria-label={t('issueShow.addAssignee')}
                                    render={
                                        <PropAction>
                                            <Plus className="size-3" aria-hidden />
                                        </PropAction>
                                    }
                                />
                                {assigneeMenuContent}
                            </DropdownMenu>
                        }
                        hint={t('issueShow.assigneesHint')}
                    >
                        <span className="flex items-center">
                            {issue.assignees.map((assignee, index) => (
                                <button
                                    key={assignee.id}
                                    type="button"
                                    data-test="assignee-remove"
                                    aria-label={t('issueShow.remove', { name: assignee.name })}
                                    title={t('issueShow.remove', { name: assignee.name })}
                                    onClick={() => toggleAssignee(assignee.id)}
                                    className={`inline-flex rounded-full ring-2 ring-card transition-shadow hover:ring-destructive/40 ${
                                        index > 0 ? '-ml-1.75' : ''
                                    }`}
                                >
                                    <Avatar name={assignee.name} title={assignee.name} />
                                </button>
                            ))}
                            <DropdownMenu>
                                <DropdownMenuTrigger
                                    aria-label={t('issueShow.addAssignee')}
                                    data-test="assignee-add"
                                    className={`grid size-5.5 place-items-center rounded-full border-2 border-card bg-muted text-muted-foreground transition-colors hover:text-foreground ${
                                        issue.assignees.length > 0 ? '-ml-1.75' : ''
                                    }`}
                                >
                                    <Plus className="size-3" aria-hidden />
                                </DropdownMenuTrigger>
                                {assigneeMenuContent}
                            </DropdownMenu>
                        </span>
                    </PropRow>

                    <PropRow
                        label={t('columns.labels')}
                        action={
                            <PropAction
                                aria-label={t('issueShow.add')}
                                data-test="label-add"
                                onClick={() => setAddingLabel((value) => !value)}
                            >
                                <Plus className="size-3" aria-hidden />
                            </PropAction>
                        }
                        hint={t('issueShow.labelsHint')}
                    >
                        {issue.labels.length > 0 ? (
                            <span className="flex flex-wrap gap-1.5">
                                {issue.labels.map((label) => (
                                    <span
                                        key={label.id}
                                        className="inline-flex items-center gap-1 rounded-full border border-border bg-card px-2 py-0.5 font-mono text-xs text-muted-foreground"
                                    >
                                        {label.name}
                                        <button
                                            type="button"
                                            data-test="label-remove"
                                            aria-label={t('issueShow.removeLabel', { name: label.name })}
                                            onClick={() => toggleLabel(label.id)}
                                            className="rounded-full text-muted-foreground transition-colors hover:text-foreground"
                                        >
                                            <X className="size-3" aria-hidden />
                                        </button>
                                    </span>
                                ))}
                            </span>
                        ) : (
                            <span className="text-sm text-muted-foreground">—</span>
                        )}
                        {addingLabel ? (
                            <form onSubmit={submitLabel} className="flex items-start gap-2">
                                <Input
                                    data-test="label-new-name"
                                    placeholder={t('issueShow.newLabelPlaceholder')}
                                    className="h-7"
                                    value={labelForm.data.name}
                                    onChange={(e) => labelForm.setData('name', e.target.value)}
                                    aria-invalid={labelForm.errors.name ? true : undefined}
                                />
                                <Button
                                    type="submit"
                                    variant="outline"
                                    size="sm"
                                    data-test="label-create-submit"
                                    disabled={labelForm.processing}
                                >
                                    {t('issueShow.add')}
                                </Button>
                            </form>
                        ) : null}
                        {labelForm.errors.name ? (
                            <p className="text-sm text-destructive">{labelForm.errors.name}</p>
                        ) : null}
                    </PropRow>

                    <PropRow
                        label={t('issueShow.dueDateLabel')}
                        action={
                            <PropAction data-test="dates-dialog-open" onClick={() => setDatesOpen(true)}>
                                {t('issueShow.edit')}
                            </PropAction>
                        }
                        hint={
                            issue.overdue
                                ? daysLate === 1
                                    ? t('issueShow.overdueFor', { count: daysLate })
                                    : t('issueShow.overdueForDays', { count: daysLate })
                                : undefined
                        }
                    >
                        <p
                            className={`font-mono text-[13px] font-semibold tabular-nums ${
                                issue.overdue ? 'text-destructive' : ''
                            }`}
                        >
                            {formatDate(issue.dueDate)}
                        </p>
                    </PropRow>

                    <PropRow label={t('issueShow.estimation')}>
                        <p className="font-mono text-[13px] tabular-nums">{formatHours(issue.estimatedHours)}</p>
                    </PropRow>

                    <PropRow
                        label={t('issueShow.milestone')}
                        action={
                            canContribute ? (
                                <PropAction data-test="milestone-dialog-open" onClick={() => setMilestoneOpen(true)}>
                                    {t('issueShow.setAction')}
                                </PropAction>
                            ) : undefined
                        }
                        hint={t('issueShow.milestoneHint')}
                    >
                        {issue.milestone ? (
                            <Link
                                href={route('milestones.show', issue.milestone.id)}
                                className="text-[13px] text-primary hover:underline"
                                data-test="issue-milestone"
                            >
                                {issue.milestone.title}
                            </Link>
                        ) : (
                            <span className="text-sm text-muted-foreground">—</span>
                        )}
                    </PropRow>

                    <PropRow
                        label={t('issueShow.parentIssue')}
                        action={
                            <PropAction data-test="parent-dialog-open" onClick={() => setParentOpen(true)}>
                                {t('issueShow.setAction')}
                            </PropAction>
                        }
                        hint={t('issueShow.softHierarchyHint')}
                    >
                        {issue.parent ? (
                            <Link
                                href={
                                    buildIssueHref?.(issue.parent.id) ??
                                    route('issues.show', issue.parent.id)
                                }
                                className="text-[13px] text-primary hover:underline"
                            >
                                {issue.parent.title}
                            </Link>
                        ) : (
                            <span className="text-sm text-muted-foreground">—</span>
                        )}
                    </PropRow>

                    <PropRow
                        label={t('issueShow.autoComplete')}
                        hint={
                            issue.children.length > 0
                                ? t('issueShow.autoCompleteHint')
                                : t('issueShow.autoCompleteNoChildren')
                        }
                    >
                        <Checkbox
                            data-test="auto-complete-toggle"
                            aria-label={t('issueShow.autoComplete')}
                            checked={issue.autoCompleteChildren}
                            onCheckedChange={(checked) => toggleAutoCompletion(checked === true)}
                        />
                    </PropRow>

                    <PropRow
                        label={t('issueShow.dependencies')}
                        action={
                            <PropAction
                                data-test="dependencies-dialog-open"
                                onClick={() => setDependenciesOpen(true)}
                            >
                                {t('issueShow.addBlocker')}
                            </PropAction>
                        }
                        hint={t('issueShow.dependenciesHint')}
                    >
                        {issue.isBlocked || issue.isUnblockable ? (
                            <span className="flex flex-wrap gap-1.5">
                                {issue.isBlocked ? (
                                    <Badge
                                        variant="destructive"
                                        className="rounded-full"
                                        data-test="blocked-badge"
                                    >
                                        {t('issueShow.blocked')}
                                    </Badge>
                                ) : null}
                                {issue.isUnblockable ? (
                                    <Badge
                                        variant="secondary"
                                        className="rounded-full bg-success/10 text-success-strong"
                                        data-test="unblockable-badge"
                                    >
                                        {t('issueShow.unblockable')}
                                    </Badge>
                                ) : null}
                            </span>
                        ) : null}
                        {issue.blockedBy.length > 0 ? (
                            <ul className="flex flex-col gap-1.5">
                                {issue.blockedBy.map((blocker) => (
                                    <li key={blocker.id} className="flex items-center gap-2">
                                        <Link
                                            href={issueHref(blocker.id)}
                                            className="min-w-0 flex-1 truncate text-[13px] text-primary hover:underline"
                                        >
                                            {blocker.title}
                                        </Link>
                                        {blocker.statusName ? (
                                            <StatusBadge
                                                name={blocker.statusName}
                                                category={blocker.statusCategory ?? 'backlog'}
                                            />
                                        ) : null}
                                        <button
                                            type="button"
                                            data-test="blocker-remove"
                                            aria-label={t('issueShow.removeBlocker', {
                                                name: blocker.title,
                                            })}
                                            onClick={() => removeBlocker(blocker.id)}
                                            className="rounded-full text-muted-foreground transition-colors hover:text-foreground"
                                        >
                                            <X className="size-3" aria-hidden />
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <span className="text-sm text-muted-foreground" data-test="no-blockers">
                                {t('issueShow.noBlockers')}
                            </span>
                        )}
                    </PropRow>

                    <PropRow label={t('issueShow.blocksThis')}>
                        {issue.blocks.length > 0 ? (
                            <ul className="flex flex-col gap-1.5">
                                {issue.blocks.map((dependent) => (
                                    <li key={dependent.id} className="flex items-center gap-2">
                                        <Link
                                            href={issueHref(dependent.id)}
                                            className="min-w-0 flex-1 truncate text-[13px] text-primary hover:underline"
                                        >
                                            {dependent.title}
                                        </Link>
                                        {dependent.statusName ? (
                                            <StatusBadge
                                                name={dependent.statusName}
                                                category={dependent.statusCategory ?? 'backlog'}
                                            />
                                        ) : null}
                                        <button
                                            type="button"
                                            data-test="blocks-remove"
                                            aria-label={t('issueShow.removeBlocks', {
                                                name: dependent.title,
                                            })}
                                            onClick={() => stopBlocking(dependent)}
                                            className="rounded-full text-muted-foreground transition-colors hover:text-foreground"
                                        >
                                            <X className="size-3" aria-hidden />
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <span className="text-sm text-muted-foreground">—</span>
                        )}
                    </PropRow>

                    <PropRow
                        label={t('knowledge.title')}
                        action={
                            <PropAction
                                data-test="knowledge-dialog-open"
                                onClick={() => setKnowledgeOpen(true)}
                            >
                                {t('issueShow.linkPages')}
                            </PropAction>
                        }
                        hint={t('issueShow.knowledgeHint')}
                    >
                        {issue.knowledgePages.length > 0 ? (
                            <ul className="flex flex-col gap-1.5">
                                {issue.knowledgePages.map((page) => (
                                    <li key={page.id} className="flex items-center gap-2">
                                        <Link
                                            href={
                                                page.projectId
                                                    ? route('knowledge.show', {
                                                          page: page.id,
                                                          project: page.projectId,
                                                      })
                                                    : route('knowledge.show', page.id)
                                            }
                                            className="min-w-0 flex-1 truncate text-[13px] text-primary hover:underline"
                                            data-test="knowledge-page-link"
                                        >
                                            {page.title}
                                        </Link>
                                        <KnowledgeProjectBadge
                                            projectId={page.projectId}
                                            name={
                                                page.projectId
                                                    ? (projects.find(
                                                          (project) => project.id === page.projectId,
                                                      )?.name ?? null)
                                                    : null
                                            }
                                        />
                                        {page.trashed ? (
                                            <Badge
                                                variant="secondary"
                                                className="rounded-full bg-warning/10 text-warning"
                                                data-test="knowledge-page-trashed"
                                            >
                                                {t('issueShow.knowledgeTrashed')}
                                            </Badge>
                                        ) : null}
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <span className="text-sm text-muted-foreground" data-test="no-knowledge-pages">
                                {t('issueShow.noKnowledgePages')}
                            </span>
                        )}
                    </PropRow>
                </CardContent>
            </Card>

            <EditDatesDialog issue={issue} open={datesOpen} onClose={() => setDatesOpen(false)} />
            <SetMilestoneDialog
                issue={issue}
                options={milestoneOptions}
                open={milestoneOpen}
                onClose={() => setMilestoneOpen(false)}
            />
            <SetParentDialog
                issue={issue}
                candidates={candidates}
                open={parentOpen}
                onClose={() => setParentOpen(false)}
            />
            <SetDependenciesDialog
                issue={issue}
                candidates={dependencyCandidates}
                open={dependenciesOpen}
                onClose={() => setDependenciesOpen(false)}
            />
            <LinkKnowledgeDialog
                issue={issue}
                projects={projects}
                open={knowledgeOpen}
                onClose={() => setKnowledgeOpen(false)}
            />

            {pendingMove ? (
                <CascadeMoveDialog
                    count={pendingMove.count}
                    targetStatusName={
                        statuses.find((status) => status.id === pendingMove.statusId)?.name ?? ''
                    }
                    onConfirm={confirmMove}
                    onClose={() => setPendingMove(null)}
                />
            ) : null}
        </aside>
    );
}

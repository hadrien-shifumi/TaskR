import { Avatar } from '@/components/issue-badges';
import { PRIORITIES } from '@/components/priority-select';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
import type { LabelRef, UserRef } from '@/types';
import type { IssueDetail } from '../types';
import { EditDatesDialog } from './edit-dates-dialog';
import { formatDate, formatHours, overdueDays } from './format';
import { SetParentDialog } from './set-parent-dialog';

type IssueSidebarProps = {
    issue: IssueDetail;
    statuses: { id: string; name: string; category: string }[];
    members: UserRef[];
    labels: LabelRef[];
    candidates: { id: string; title: string }[];
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
 * priority, assignees, labels, due date, estimation, parent.
 */
export function IssueSidebar({ issue, statuses, members, labels, candidates }: IssueSidebarProps) {
    const { t } = useTranslation();
    const [datesOpen, setDatesOpen] = useState(false);
    const [parentOpen, setParentOpen] = useState(false);
    const [addingLabel, setAddingLabel] = useState(false);

    const move = (statusId: string) => {
        router.post(route('issues.move', issue.id), { status_id: statusId });
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

    const toggleLabel = (labelId: string) => {
        const current = issue.labels.map((label) => label.id);
        const next = current.includes(labelId)
            ? current.filter((id) => id !== labelId)
            : [...current, labelId];
        router.post(route('issues.labels', issue.id), { label_ids: next });
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
        <aside className="lg:sticky lg:top-[76px]">
            <Card className="py-5">
                <CardContent className="flex flex-col px-5">
                    <PropRow label={t('issues.fields.status')} hint={t('issueShow.statusHint')}>
                        <Select value={issue.status.id} onValueChange={(value) => value && move(value)}>
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
                        label={t('columns.dueDate')}
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
                                href={route('issues.show', issue.parent.id)}
                                className="text-[13px] text-primary hover:underline"
                            >
                                {issue.parent.title}
                            </Link>
                        ) : (
                            <span className="text-sm text-muted-foreground">—</span>
                        )}
                    </PropRow>
                </CardContent>
            </Card>

            <EditDatesDialog issue={issue} open={datesOpen} onClose={() => setDatesOpen(false)} />
            <SetParentDialog
                issue={issue}
                candidates={candidates}
                open={parentOpen}
                onClose={() => setParentOpen(false)}
            />
        </aside>
    );
}

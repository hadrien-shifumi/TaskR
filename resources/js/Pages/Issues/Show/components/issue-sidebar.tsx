import { PriorityBadge, StatusBadge } from '@/components/issue-badges';
import { FormDialog } from '@/components/form-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label as LabelField } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { LabelRef, UserRef } from '@/types';
import type { IssueDetail } from '../types';
import { formatHours } from './time-entries-section';

type IssueSidebarProps = {
    issue: IssueDetail;
    statuses: { id: string; name: string; category: string }[];
    members: UserRef[];
    labels: LabelRef[];
    candidates: { id: string; title: string }[];
    onEditIssue: () => void;
};

function ToggleChipList({
    items,
    selectedIds,
    onToggle,
}: {
    items: { id: string; name: string }[];
    selectedIds: string[];
    onToggle: (id: string) => void;
}) {
    return (
        <div className="flex flex-wrap gap-2">
            {items.map((item) => (
                <button
                    key={item.id}
                    type="button"
                    onClick={() => onToggle(item.id)}
                    className={`rounded-md border px-2 py-1 text-xs ${
                        selectedIds.includes(item.id)
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'bg-background text-muted-foreground'
                    }`}
                >
                    {item.name}
                </button>
            ))}
        </div>
    );
}

/** Metadata column: status, parent, estimate and assignee/label toggles. */
export function IssueSidebar({ issue, statuses, members, labels, candidates, onEditIssue }: IssueSidebarProps) {
    const [parentError, setParentError] = useState<string | null>(null);
    const [editingEstimate, setEditingEstimate] = useState(false);

    const estimateForm = useForm<{ estimated_hours: string }>({
        estimated_hours: issue.estimatedHours !== null ? String(issue.estimatedHours) : '',
    });

    const move = (statusId: string) => {
        router.post(route('issues.move', issue.id), { status_id: statusId });
    };

    /**
     * The cycle rule lives in the action; the UI only surfaces its refusal
     * at the selector that triggered it (ADR-5).
     */
    const setParent = (parentId: string) => {
        setParentError(null);
        router.post(
            route('issues.parent', issue.id),
            {
                parent_id: parentId === 'none' ? null : parentId,
            },
            {
                onHttpException: (response) => {
                    setParentError(
                        response.status === 422
                            ? 'This parent would create a cycle in the hierarchy.'
                            : 'The parent could not be set.',
                    );
                    return false;
                },
            },
        );
    };

    const toggleAssignee = (userId: string) => {
        const current = issue.assignees.map((a) => a.id);
        const next = current.includes(userId)
            ? current.filter((id) => id !== userId)
            : [...current, userId];
        router.post(route('issues.assignees', issue.id), { assignees: next });
    };

    const toggleLabel = (labelId: string) => {
        const current = issue.labels.map((l) => l.id);
        const next = current.includes(labelId)
            ? current.filter((id) => id !== labelId)
            : [...current, labelId];
        router.post(route('issues.labels', issue.id), { label_ids: next });
    };

    const remove = () => {
        if (!window.confirm(`Delete issue "${issue.title}"?`)) return;
        router.delete(route('issues.destroy', issue.id));
    };

    const submitEstimate = (e: React.FormEvent) => {
        e.preventDefault();
        estimateForm.post(route('issues.estimate', issue.id), {
            onSuccess: () => setEditingEstimate(false),
        });
    };

    return (
        <aside className="space-y-4 text-sm">
            <div className="space-y-1">
                <p className="font-medium text-muted-foreground">Project</p>
                <p>{issue.project.name}</p>
            </div>
            <div className="space-y-1">
                <p className="font-medium text-muted-foreground">Status</p>
                <Select value={issue.status.id} onValueChange={(v) => v && move(v)}>
                    <SelectTrigger data-test="status-select">
                        <SelectValue>{issue.status.name}</SelectValue>
                    </SelectTrigger>
                    <SelectContent>
                        {statuses.map((s) => (
                            <SelectItem key={s.id} value={s.id}>
                                {s.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <StatusBadge name={issue.status.name} category={issue.status.category} />
            </div>
            <div className="space-y-1">
                <p className="font-medium text-muted-foreground">Parent</p>
                <Select
                    value={issue.parent?.id ?? 'none'}
                    onValueChange={(v) => v && setParent(v)}
                >
                    <SelectTrigger>
                        <SelectValue>{issue.parent?.title ?? 'No parent'}</SelectValue>
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="none">No parent</SelectItem>
                        {candidates.map((c) => (
                            <SelectItem key={c.id} value={c.id}>
                                {c.title}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                {parentError ? (
                    <p role="alert" className="text-xs text-destructive">
                        {parentError}
                    </p>
                ) : null}
            </div>
            <div className="space-y-1">
                <p className="font-medium text-muted-foreground">Priority</p>
                <PriorityBadge priority={issue.priority} />
            </div>
            <div className="space-y-1">
                <p className="font-medium text-muted-foreground">Due date</p>
                <p
                    className={`font-mono text-sm tabular-nums ${
                        issue.overdue ? 'font-medium text-destructive' : ''
                    }`}
                >
                    {issue.dueDate ? new Date(issue.dueDate).toLocaleDateString() : '—'}
                    {issue.overdue ? ' (overdue)' : ''}
                </p>
            </div>
            <div className="space-y-1">
                <p className="font-medium text-muted-foreground">Estimate</p>
                <div className="flex items-center gap-2">
                    <p>{formatHours(issue.estimatedHours)}</p>
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                            setEditingEstimate(true);
                            estimateForm.setData('estimated_hours', issue.estimatedHours !== null ? String(issue.estimatedHours) : '');
                        }}
                    >
                        Edit
                    </Button>
                </div>
                {issue.loggedHours > 0 ? (
                    <p className="text-xs text-muted-foreground">
                        {issue.loggedHours}h logged
                        {issue.remainingHours !== null ? (
                            <span className={issue.remainingHours < 0 ? 'text-destructive' : ''}>
                                {' '}· {formatHours(issue.remainingHours)} left
                            </span>
                        ) : null}
                    </p>
                ) : null}
            </div>
            <div className="space-y-1">
                <p className="font-medium text-muted-foreground">Assignees</p>
                <ToggleChipList
                    items={members}
                    selectedIds={issue.assignees.map((a) => a.id)}
                    onToggle={toggleAssignee}
                />
            </div>
            <div className="space-y-1">
                <p className="font-medium text-muted-foreground">Labels</p>
                <ToggleChipList
                    items={labels}
                    selectedIds={issue.labels.map((l) => l.id)}
                    onToggle={toggleLabel}
                />
            </div>
            <div className="flex gap-2 pt-2">
                <Button variant="outline" size="sm" onClick={onEditIssue}>
                    Edit
                </Button>
                <Button variant="ghost" size="sm" onClick={remove}>
                    Delete
                </Button>
            </div>

            <FormDialog
                open={editingEstimate}
                onOpenChange={(open) => {
                    if (!open) setEditingEstimate(false);
                }}
                title="Edit estimate"
                description="Set the estimated hours for this issue. Leave empty to remove the estimate."
                submitLabel="Save"
                processing={estimateForm.processing}
                onSubmit={submitEstimate}
            >
                <div className="grid gap-2">
                    <LabelField htmlFor="edit-estimate">Estimated hours</LabelField>
                    <Input
                        id="edit-estimate"
                        type="number"
                        step="any"
                        min="0"
                        placeholder="e.g. 8"
                        value={estimateForm.data.estimated_hours}
                        onChange={(e) => estimateForm.setData('estimated_hours', e.target.value)}
                    />
                    {estimateForm.errors.estimated_hours ? (
                        <p className="text-sm text-destructive">{estimateForm.errors.estimated_hours}</p>
                    ) : null}
                </div>
            </FormDialog>
        </aside>
    );
}

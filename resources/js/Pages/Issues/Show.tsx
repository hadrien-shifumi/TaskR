import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label as LabelField } from '@/components/ui/label';
import { MarkdownView } from '@/components/ui/markdown-editor';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface UserRef {
    id: string;
    name: string;
}

type IssueDetail = {
    id: string;
    title: string;
    description: string | null;
    project: { id: string; name: string };
    status: { id: string; name: string; category: string };
    priority: string;
    dueDate: string | null;
    overdue: boolean;
    estimatedHours: number | null;
    loggedHours: number;
    remainingHours: number | null;
    assignees: UserRef[];
    labels: { id: string; name: string; color: string }[];
    parent: { id: string; title: string; statusName: string | null } | null;
    children: {
        id: string;
        title: string;
        statusName: string | null;
        assignees: UserRef[];
    }[];
};

type TimeEntry = {
    id: string;
    authorId: number;
    authorName: string;
    workedAt: string | null;
    hours: number;
    note: string | null;
};

type PageProps = {
    issue: IssueDetail;
    comments: {
        id: string;
        body: string;
        authorId: number;
        authorName: string;
        createdAt: string | null;
    }[];
    timeEntries: TimeEntry[];
    statuses: { id: string; name: string; category: string }[];
    members: UserRef[];
    labels: { id: string; name: string; color: string }[];
    candidates: { id: string; title: string }[];
};

const PRIORITIES = ['none', 'low', 'high', 'urgent'];

export default function Show() {
    const { issue, comments, timeEntries, statuses, members, labels, candidates } = usePage<PageProps>().props;
    const { flash } = usePage<{ flash?: { status?: string } }>().props;
    const { auth } = usePage<{ auth: { user: { id: number; name: string; email: string } | null } }>().props;
    const [editing, setEditing] = useState(false);
    const [editingComment, setEditingComment] = useState<string | null>(null);
    const [editingEstimate, setEditingEstimate] = useState(false);
    const [editingTimeEntry, setEditingTimeEntry] = useState<string | null>(null);

    const commentForm = useForm({ body: '' });
    const commentEditForm = useForm({ body: '' });

    const estimateForm = useForm<{ estimated_hours: string }>({
        estimated_hours: issue.estimatedHours !== null ? String(issue.estimatedHours) : '',
    });

    const timeEntryForm = useForm({
        hours: '',
        worked_at: new Date().toISOString().slice(0, 10),
        note: '',
    });

    const timeEntryEditForm = useForm({
        hours: '',
        worked_at: '',
        note: '',
    });

    const editForm = useForm({
        title: issue.title,
        description: issue.description ?? '',
        priority: issue.priority,
        due_date: issue.dueDate ? issue.dueDate.slice(0, 10) : '',
    });

    const submitEdit = (e: React.FormEvent) => {
        e.preventDefault();
        editForm.patch(route('issues.update', issue.id), {
            onSuccess: () => setEditing(false),
        });
    };

    const move = (statusId: string) => {
        router.post(route('issues.move', issue.id), { status_id: statusId });
    };

    const setParent = (parentId: string) => {
        router.post(route('issues.parent', issue.id), {
            parent_id: parentId === 'none' ? null : parentId,
        });
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

    const submitComment = (e: React.FormEvent) => {
        e.preventDefault();
        commentForm.post(route('comments.store', issue.id), {
            onSuccess: () => commentForm.reset(),
        });
    };

    const submitCommentEdit = (e: React.FormEvent, commentId: string) => {
        e.preventDefault();
        commentEditForm.patch(route('comments.update', [issue.id, commentId]), {
            onSuccess: () => setEditingComment(null),
        });
    };

    const removeComment = (commentId: string) => {
        if (!window.confirm('Delete this comment?')) return;
        router.delete(route('comments.destroy', [issue.id, commentId]));
    };

    const submitEstimate = (e: React.FormEvent) => {
        e.preventDefault();
        estimateForm.post(route('issues.estimate', issue.id), {
            onSuccess: () => setEditingEstimate(false),
        });
    };

    const submitTimeEntry = (e: React.FormEvent) => {
        e.preventDefault();
        timeEntryForm.post(route('issues.time-entries.store', issue.id), {
            onSuccess: () => timeEntryForm.reset('hours', 'note'),
        });
    };

    const submitTimeEntryEdit = (e: React.FormEvent, entryId: string) => {
        e.preventDefault();
        timeEntryEditForm.patch(route('issues.time-entries.update', [issue.id, entryId]), {
            onSuccess: () => setEditingTimeEntry(null),
        });
    };

    const removeTimeEntry = (entryId: string) => {
        if (!window.confirm('Delete this time entry?')) return;
        router.delete(route('issues.time-entries.destroy', [issue.id, entryId]));
    };

    const formatHours = (hours: number | null): string => {
        if (hours === null) return '—';
        return `${hours}h`;
    };

    return (
        <AppLayout title={issue.title}>
            <Head title={issue.title} />

            {flash?.status ? (
                <p className="mb-4 rounded-md border bg-muted/50 px-4 py-2 text-sm">{flash.status}</p>
            ) : null}

            <div className="grid gap-8 lg:grid-cols-[1fr_280px]">
                <div className="min-w-0 space-y-6">
                    {issue.parent ? (
                        <p className="text-sm text-muted-foreground">
                            Parent:{' '}
                            <Link href={route('issues.show', issue.parent.id)} className="hover:underline">
                                {issue.parent.title}
                            </Link>
                        </p>
                    ) : null}

                    <div>
                        <h2 className="mb-2 text-lg font-semibold">Description</h2>
                        {issue.description ? (
                            <MarkdownView value={issue.description} className="text-sm text-muted-foreground" />
                        ) : (
                            <p className="text-sm italic text-muted-foreground">No description.</p>
                        )}
                    </div>

                    <div>
                        <h2 className="mb-2 text-lg font-semibold">
                            Children ({issue.children.length})
                        </h2>
                        <ul className="divide-y rounded-md border">
                            {issue.children.map((child) => (
                                <li key={child.id} className="flex items-center gap-3 px-4 py-2 text-sm">
                                    <Link href={route('issues.show', child.id)} className="font-medium hover:underline">
                                        {child.title}
                                    </Link>
                                    {child.statusName ? <Badge variant="outline">{child.statusName}</Badge> : null}
                                    <span className="ml-auto text-muted-foreground">
                                        {child.assignees.map((a) => a.name).join(', ')}
                                    </span>
                                </li>
                            ))}
                            {issue.children.length === 0 ? (
                                <li className="px-4 py-3 text-sm text-muted-foreground">No children.</li>
                            ) : null}
                        </ul>
                    </div>

                    <div>
                        <h2 className="mb-2 text-lg font-semibold">Comments ({comments.length})</h2>
                        <ul className="space-y-3">
                            {comments.map((comment) => (
                                <li key={comment.id} className="rounded-md border px-4 py-3">
                                    <div className="mb-1 flex items-center gap-2 text-sm">
                                        <span className="font-medium">{comment.authorName}</span>
                                        <span className="text-muted-foreground">
                                            {comment.createdAt
                                                ? new Date(comment.createdAt).toLocaleString()
                                                : ''}
                                        </span>
                                        {auth.user && auth.user.id === comment.authorId ? (
                                            <span className="ml-auto flex gap-1">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => {
                                                        setEditingComment(comment.id);
                                                        commentEditForm.setData('body', comment.body);
                                                    }}
                                                >
                                                    Edit
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => removeComment(comment.id)}
                                                >
                                                    Delete
                                                </Button>
                                            </span>
                                        ) : null}
                                    </div>
                                    {editingComment === comment.id ? (
                                        <form onSubmit={(e) => submitCommentEdit(e, comment.id)} className="grid gap-2">
                                            <Textarea
                                                value={commentEditForm.data.body}
                                                onChange={(e) => commentEditForm.setData('body', e.target.value)}
                                                required
                                            />
                                            <div className="flex gap-2">
                                                <Button type="submit" size="sm" disabled={commentEditForm.processing}>
                                                    Save
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => setEditingComment(null)}
                                                >
                                                    Cancel
                                                </Button>
                                            </div>
                                        </form>
                                    ) : (
                                        <p className="whitespace-pre-wrap text-sm text-muted-foreground">
                                            {comment.body}
                                        </p>
                                    )}
                                </li>
                            ))}
                        </ul>
                        <form onSubmit={submitComment} className="mt-4 grid gap-2">
                            <Textarea
                                placeholder="Add a comment..."
                                value={commentForm.data.body}
                                onChange={(e) => commentForm.setData('body', e.target.value)}
                                required
                            />
                            {commentForm.errors.body ? (
                                <p className="text-sm text-destructive">{commentForm.errors.body}</p>
                            ) : null}
                            <Button type="submit" size="sm" disabled={commentForm.processing} className="justify-self-start">
                                Comment
                            </Button>
                        </form>
                    </div>

                    <div>
                        <div className="mb-2 flex items-center justify-between">
                            <h2 className="text-lg font-semibold">Time</h2>
                            <p className="text-sm text-muted-foreground">
                                {formatHours(issue.loggedHours)}
                                {issue.estimatedHours !== null ? ` / ${formatHours(issue.estimatedHours)}` : ''}
                                {issue.remainingHours !== null ? (
                                    <span className={issue.remainingHours < 0 ? 'text-destructive' : ''}>
                                        {' '}({formatHours(issue.remainingHours)} left)
                                    </span>
                                ) : null}
                            </p>
                        </div>
                        <ul className="space-y-3">
                            {timeEntries.map((entry) => (
                                <li key={entry.id} className="rounded-md border px-4 py-3">
                                    <div className="mb-1 flex items-center gap-2 text-sm">
                                        <span className="font-medium">{entry.authorName}</span>
                                        <span className="text-muted-foreground">
                                            {entry.workedAt ? new Date(entry.workedAt).toLocaleDateString() : ''}
                                        </span>
                                        <span className="font-medium">{entry.hours}h</span>
                                        {auth.user && auth.user.id === entry.authorId ? (
                                            <span className="ml-auto flex gap-1">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => {
                                                        setEditingTimeEntry(entry.id);
                                                        timeEntryEditForm.setData({
                                                            hours: String(entry.hours),
                                                            worked_at: entry.workedAt ? entry.workedAt.slice(0, 10) : '',
                                                            note: entry.note ?? '',
                                                        });
                                                    }}
                                                >
                                                    Edit
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => removeTimeEntry(entry.id)}
                                                >
                                                    Delete
                                                </Button>
                                            </span>
                                        ) : null}
                                    </div>
                                    {editingTimeEntry === entry.id ? (
                                        <form onSubmit={(e) => submitTimeEntryEdit(e, entry.id)} className="grid gap-2">
                                            <div className="grid grid-cols-2 gap-2">
                                                <Input
                                                    type="number"
                                                    step="0.25"
                                                    min="0.01"
                                                    placeholder="Hours"
                                                    value={timeEntryEditForm.data.hours}
                                                    onChange={(e) => timeEntryEditForm.setData('hours', e.target.value)}
                                                    required
                                                />
                                                <Input
                                                    type="date"
                                                    value={timeEntryEditForm.data.worked_at}
                                                    onChange={(e) => timeEntryEditForm.setData('worked_at', e.target.value)}
                                                />
                                            </div>
                                            <Textarea
                                                placeholder="Note (optional)"
                                                value={timeEntryEditForm.data.note}
                                                onChange={(e) => timeEntryEditForm.setData('note', e.target.value)}
                                            />
                                            <div className="flex gap-2">
                                                <Button type="submit" size="sm" disabled={timeEntryEditForm.processing}>
                                                    Save
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => setEditingTimeEntry(null)}
                                                >
                                                    Cancel
                                                </Button>
                                            </div>
                                        </form>
                                    ) : (
                                        <>
                                            {entry.note ? (
                                                <p className="whitespace-pre-wrap text-sm text-muted-foreground">
                                                    {entry.note}
                                                </p>
                                            ) : null}
                                        </>
                                    )}
                                </li>
                            ))}
                            {timeEntries.length === 0 ? (
                                <li className="px-4 py-3 text-sm text-muted-foreground">No time logged yet.</li>
                            ) : null}
                        </ul>
                        <form onSubmit={submitTimeEntry} className="mt-4 grid gap-2">
                            <div className="grid grid-cols-2 gap-2">
                                <Input
                                    type="number"
                                    step="0.25"
                                    min="0.01"
                                    placeholder="Hours"
                                    value={timeEntryForm.data.hours}
                                    onChange={(e) => timeEntryForm.setData('hours', e.target.value)}
                                    required
                                />
                                <Input
                                    type="date"
                                    value={timeEntryForm.data.worked_at}
                                    onChange={(e) => timeEntryForm.setData('worked_at', e.target.value)}
                                />
                            </div>
                            <Textarea
                                placeholder="Note (optional)"
                                value={timeEntryForm.data.note}
                                onChange={(e) => timeEntryForm.setData('note', e.target.value)}
                            />
                            {timeEntryForm.errors.hours ? (
                                <p className="text-sm text-destructive">{timeEntryForm.errors.hours}</p>
                            ) : null}
                            <Button type="submit" size="sm" disabled={timeEntryForm.processing} className="justify-self-start">
                                Log time
                            </Button>
                        </form>
                    </div>
                </div>

                <aside className="space-y-4 text-sm">
                    <div className="space-y-1">
                        <p className="font-medium text-muted-foreground">Project</p>
                        <p>{issue.project.name}</p>
                    </div>
                    <div className="space-y-1">
                        <p className="font-medium text-muted-foreground">Status</p>
                        <Select value={issue.status.id} onValueChange={(v) => v && move(v)}>
                            <SelectTrigger>
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
                    </div>
                    <div className="space-y-1">
                        <p className="font-medium text-muted-foreground">Priority</p>
                        <p>{issue.priority}</p>
                    </div>
                    <div className="space-y-1">
                        <p className="font-medium text-muted-foreground">Due date</p>
                        <p className={issue.overdue ? 'font-medium text-destructive' : ''}>
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
                        <div className="flex flex-wrap gap-2">
                            {members.map((m) => (
                                <button
                                    key={m.id}
                                    type="button"
                                    onClick={() => toggleAssignee(m.id)}
                                    className={`rounded-md border px-2 py-1 text-xs ${
                                        issue.assignees.some((a) => a.id === m.id)
                                            ? 'border-primary bg-primary text-primary-foreground'
                                            : 'bg-background text-muted-foreground'
                                    }`}
                                >
                                    {m.name}
                                </button>
                            ))}
                        </div>
                    </div>
                    <div className="space-y-1">
                        <p className="font-medium text-muted-foreground">Labels</p>
                        <div className="flex flex-wrap gap-2">
                            {labels.map((l) => (
                                <button
                                    key={l.id}
                                    type="button"
                                    onClick={() => toggleLabel(l.id)}
                                    className={`rounded-md border px-2 py-1 text-xs ${
                                        issue.labels.some((x) => x.id === l.id)
                                            ? 'border-primary bg-primary text-primary-foreground'
                                            : 'bg-background text-muted-foreground'
                                    }`}
                                >
                                    {l.name}
                                </button>
                            ))}
                        </div>
                    </div>
                    <div className="flex gap-2 pt-2">
                        <Button variant="outline" size="sm" onClick={() => setEditing(true)}>
                            Edit
                        </Button>
                        <Button variant="ghost" size="sm" onClick={remove}>
                            Delete
                        </Button>
                    </div>
                </aside>
            </div>

            <Dialog
                open={editing}
                onOpenChange={(open) => {
                    if (!open) setEditing(false);
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Edit issue</DialogTitle>
                        <DialogDescription>Update the issue content.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submitEdit} className="grid gap-4">
                        <div className="grid gap-2">
                            <LabelField htmlFor="edit-title">Title</LabelField>
                            <Input
                                id="edit-title"
                                value={editForm.data.title}
                                onChange={(e) => editForm.setData('title', e.target.value)}
                                required
                            />
                            {editForm.errors.title ? (
                                <p className="text-sm text-destructive">{editForm.errors.title}</p>
                            ) : null}
                        </div>
                        <div className="grid gap-2">
                            <LabelField htmlFor="edit-description">Description</LabelField>
                            <Textarea
                                id="edit-description"
                                value={editForm.data.description}
                                onChange={(e) => editForm.setData('description', e.target.value)}
                            />
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="grid gap-2">
                                <LabelField>Priority</LabelField>
                                <Select
                                    value={editForm.data.priority}
                                    onValueChange={(v) => {
                                        if (v) editForm.setData('priority', v);
                                    }}
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {PRIORITIES.map((p) => (
                                            <SelectItem key={p} value={p}>
                                                {p}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="grid gap-2">
                                <LabelField htmlFor="edit-due">Due date</LabelField>
                                <Input
                                    id="edit-due"
                                    type="date"
                                    value={editForm.data.due_date}
                                    onChange={(e) => editForm.setData('due_date', e.target.value)}
                                />
                            </div>
                        </div>
                        <DialogFooter>
                            <Button type="submit" disabled={editForm.processing}>
                                Save
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={editingEstimate}
                onOpenChange={(open) => {
                    if (!open) setEditingEstimate(false);
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Edit estimate</DialogTitle>
                        <DialogDescription>Set the estimated hours for this issue. Leave empty to remove the estimate.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submitEstimate} className="grid gap-4">
                        <div className="grid gap-2">
                            <LabelField htmlFor="edit-estimate">Estimated hours</LabelField>
                            <Input
                                id="edit-estimate"
                                type="number"
                                step="0.25"
                                min="0"
                                placeholder="e.g. 8"
                                value={estimateForm.data.estimated_hours}
                                onChange={(e) => estimateForm.setData('estimated_hours', e.target.value)}
                            />
                            {estimateForm.errors.estimated_hours ? (
                                <p className="text-sm text-destructive">{estimateForm.errors.estimated_hours}</p>
                            ) : null}
                        </div>
                        <DialogFooter>
                            <Button type="submit" disabled={estimateForm.processing}>
                                Save
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

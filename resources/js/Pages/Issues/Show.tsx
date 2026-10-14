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

type PageProps = {
    issue: IssueDetail;
    comments: {
        id: string;
        body: string;
        authorId: number;
        authorName: string;
        createdAt: string | null;
    }[];
    statuses: { id: string; name: string; category: string }[];
    members: UserRef[];
    labels: { id: string; name: string; color: string }[];
    candidates: { id: string; title: string }[];
};

const PRIORITIES = ['none', 'low', 'high', 'urgent'];

export default function Show() {
    const { issue, comments, statuses, members, labels, candidates } = usePage<PageProps>().props;
    const { flash } = usePage<{ flash?: { status?: string } }>().props;
    const { auth } = usePage<{ auth: { user: { id: number; name: string; email: string } | null } }>().props;
    const [editing, setEditing] = useState(false);
    const [editingComment, setEditingComment] = useState<string | null>(null);

    const commentForm = useForm({ body: '' });
    const commentEditForm = useForm({ body: '' });

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
        </AppLayout>
    );
}

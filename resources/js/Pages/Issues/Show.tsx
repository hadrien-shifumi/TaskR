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
    statuses: { id: string; name: string; category: string }[];
    members: UserRef[];
    labels: { id: string; name: string; color: string }[];
    candidates: { id: string; title: string }[];
};

const PRIORITIES = ['none', 'low', 'high', 'urgent'];

export default function Show() {
    const { issue, statuses, members, labels, candidates } = usePage<PageProps>().props;
    const { flash } = usePage<{ flash?: { status?: string } }>().props;
    const [editing, setEditing] = useState(false);

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
                            <p className="whitespace-pre-wrap text-sm text-muted-foreground">{issue.description}</p>
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
                                <SelectValue />
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
                                <SelectValue placeholder="No parent" />
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

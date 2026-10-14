import AppLayout from '@/layouts/app-layout';
import { SettingsShell } from '@/components/settings-shell';
import { StatusBadge } from '@/components/issue-badges';
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
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { GripVertical, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import type { PageSharedProps } from '@/types';

interface StatusRow {
    id: string;
    name: string;
    category: string;
    position: number;
    issuesCount: number;
    issueIds: string[];
}

const CATEGORIES = [
    { value: 'backlog', label: 'Backlog' },
    { value: 'in_progress', label: 'In Progress' },
    { value: 'done', label: 'Done' },
    { value: 'cancelled', label: 'Cancelled' },
];

export default function Statuses() {
    const { statuses } = usePage<{ statuses: StatusRow[] }>().props;
    const { workspace, flash } = usePage<PageSharedProps & { flash?: { status?: string } }>().props;
    const isOwner = workspace?.role === 'owner';
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState<StatusRow | null>(null);
    const [deleting, setDeleting] = useState<StatusRow | null>(null);
    const [migrateIds, setMigrateIds] = useState<string[]>([]);
    const [migrateTo, setMigrateTo] = useState('');
    const [migrationError, setMigrationError] = useState<string | null>(null);
    const [migrating, setMigrating] = useState(false);
    const [dragIndex, setDragIndex] = useState<number | null>(null);
    const [overIndex, setOverIndex] = useState<number | null>(null);

    const createForm = useForm({ name: '', category: 'backlog' });
    const editForm = useForm({ name: '', category: 'backlog' });

    const submitCreate = (e: React.FormEvent) => {
        e.preventDefault();
        createForm.post(route('statuses.store'), {
            onSuccess: () => {
                setCreating(false);
                createForm.reset();
            },
        });
    };

    const submitEdit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!editing) return;
        editForm.patch(route('statuses.update', editing.id), {
            onSuccess: () => setEditing(null),
        });
    };

    const openDelete = (status: StatusRow) => {
        setDeleting(status);
        setMigrateIds(status.issueIds);
        setMigrateTo('');
        setMigrationError(null);
    };

    /**
     * Deleting a used status is refused by the action. The dialog composes
     * existing actions instead: move each issue to the chosen status, then
     * delete. If the state changed in the meantime the action refuses and
     * the error surfaces here — no client-side copy of the rule.
     */
    const moveOne = (issueId: string, statusId: string) =>
        new Promise<boolean>((resolve) => {
            router.post(route('issues.move', issueId), { status_id: statusId }, {
                onSuccess: () => resolve(true),
                onHttpException: () => {
                    setMigrationError('The migration was refused. Reload and retry.');
                    resolve(false);
                    return false;
                },
            });
        });

    const confirmDelete = async () => {
        if (!deleting) return;

        if (migrateIds.length === 0) {
            router.delete(route('statuses.destroy', deleting.id), {
                onSuccess: () => setDeleting(null),
            });
            return;
        }

        if (!migrateTo) {
            setMigrationError('Choose the status the issues move to first.');
            return;
        }

        setMigrating(true);
        for (const issueId of migrateIds) {
            const moved = await moveOne(issueId, migrateTo);
            if (!moved) {
                setMigrating(false);
                return;
            }
        }

        router.delete(route('statuses.destroy', deleting.id), {
            onHttpException: (response) => {
                setMigrationError(
                    response.status === 409
                        ? `Refused — "${deleting.name}" received new issues since the page loaded. Retry the migration.`
                        : 'The deletion was refused.',
                );
                setMigrating(false);
                return false;
            },
            onSuccess: () => {
                setMigrating(false);
                setDeleting(null);
            },
        });
    };

    const reorder = (ids: string[]) => {
        router.post(route('statuses.reorder'), { ids });
    };

    const move = (status: StatusRow, delta: number) => {
        const ids = statuses.map((s) => s.id);
        const index = ids.indexOf(status.id);
        const target = index + delta;
        if (target < 0 || target >= ids.length) return;
        [ids[index], ids[target]] = [ids[target], ids[index]];
        reorder(ids);
    };

    const dropOn = (index: number) => {
        if (dragIndex === null || dragIndex === index) {
            setDragIndex(null);
            setOverIndex(null);
            return;
        }
        const ids = statuses.map((s) => s.id);
        const [moved] = ids.splice(dragIndex, 1);
        ids.splice(index, 0, moved);
        reorder(ids);
        setDragIndex(null);
        setOverIndex(null);
    };

    return (
        <AppLayout title="Settings">
            <SettingsShell
                title="Workspace statuses"
                description="Defined once for every project. The order below applies across the UI."
                actions={
                    <Button size="sm" onClick={() => setCreating(true)}>
                        New status
                    </Button>
                }
            >
                <Head title="Statuses" />

                {flash?.status ? (
                    <p className="mb-4 rounded-lg border bg-muted/50 px-4 py-2.5 text-sm">{flash.status}</p>
                ) : null}

                <div className="space-y-2">
                    {statuses.map((status, index) => (
                        <div
                            key={status.id}
                            draggable={isOwner}
                            data-test={`status-row-${status.name}`}
                            onDragStart={() => setDragIndex(index)}
                            onDragEnd={() => {
                                setDragIndex(null);
                                setOverIndex(null);
                            }}
                            onDragOver={(e) => {
                                if (dragIndex === null) return;
                                e.preventDefault();
                                setOverIndex(index);
                            }}
                            onDrop={(e) => {
                                e.preventDefault();
                                dropOn(index);
                            }}
                            className={`flex items-center gap-3 rounded-lg border bg-card px-3.5 py-3 shadow-xs ${
                                overIndex === index && dragIndex !== null && dragIndex !== index
                                    ? 'border-primary ring-2 ring-ring'
                                    : ''
                            } ${dragIndex === index ? 'opacity-40' : ''}`}
                        >
                            {isOwner ? (
                                <span aria-label="Reorder" className="cursor-grab text-muted-foreground">
                                    <GripVertical className="size-4" />
                                </span>
                            ) : null}
                            <strong className="text-sm">{status.name}</strong>
                            <StatusBadge name={status.category} category={status.category} />
                            <span className="ml-auto font-mono text-xs text-muted-foreground tabular-nums">
                                {status.issuesCount} {status.issuesCount === 1 ? 'issue' : 'issues'}
                            </span>
                            {isOwner ? (
                                <div className="flex gap-1">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        disabled={status.position === 0}
                                        onClick={() => move(status, -1)}
                                    >
                                        Up
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        disabled={status.position === statuses.length - 1}
                                        onClick={() => move(status, 1)}
                                    >
                                        Down
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => {
                                            setEditing(status);
                                            editForm.setData({ name: status.name, category: status.category });
                                        }}
                                    >
                                        Edit
                                    </Button>
                                    <Button variant="ghost" size="sm" onClick={() => openDelete(status)}>
                                        Delete
                                    </Button>
                                </div>
                            ) : null}
                        </div>
                    ))}
                </div>

                <Dialog open={creating} onOpenChange={setCreating}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>New status</DialogTitle>
                            <DialogDescription>Add a status to this workspace.</DialogDescription>
                        </DialogHeader>
                        <form onSubmit={submitCreate} className="grid gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor="new-name">Name</Label>
                                <Input
                                    id="new-name"
                                    value={createForm.data.name}
                                    onChange={(e) => createForm.setData('name', e.target.value)}
                                    required
                                />
                                {createForm.errors.name ? (
                                    <p className="text-sm text-destructive">{createForm.errors.name}</p>
                                ) : null}
                            </div>
                            <div className="grid gap-2">
                                <Label>Category</Label>
                                <Select
                                    value={createForm.data.category}
                                    onValueChange={(v) => {
                                        if (v) createForm.setData('category', v);
                                    }}
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {CATEGORIES.map((c) => (
                                            <SelectItem key={c.value} value={c.value}>
                                                {c.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <DialogFooter>
                                <Button type="submit" disabled={createForm.processing}>
                                    Create
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>

                <Dialog
                    open={editing !== null}
                    onOpenChange={(open) => {
                        if (!open) setEditing(null);
                    }}
                >
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Edit status</DialogTitle>
                            <DialogDescription>Rename or change category.</DialogDescription>
                        </DialogHeader>
                        <form onSubmit={submitEdit} className="grid gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor="edit-name">Name</Label>
                                <Input
                                    id="edit-name"
                                    value={editForm.data.name}
                                    onChange={(e) => editForm.setData('name', e.target.value)}
                                    required
                                />
                                {editForm.errors.name ? (
                                    <p className="text-sm text-destructive">{editForm.errors.name}</p>
                                ) : null}
                            </div>
                            <div className="grid gap-2">
                                <Label>Category</Label>
                                <Select
                                    value={editForm.data.category}
                                    onValueChange={(v) => {
                                        if (v) editForm.setData('category', v);
                                    }}
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {CATEGORIES.map((c) => (
                                            <SelectItem key={c.value} value={c.value}>
                                                {c.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
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
                    open={deleting !== null}
                    onOpenChange={(open) => {
                        if (!open) setDeleting(null);
                    }}
                >
                    {deleting ? (
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>
                                    {migrateIds.length > 0
                                        ? `"${deleting.name}" is used by ${migrateIds.length} ${
                                              migrateIds.length === 1 ? 'issue' : 'issues'
                                          }`
                                        : `Delete "${deleting.name}"?`}
                                </DialogTitle>
                                <DialogDescription>
                                    {migrateIds.length > 0
                                        ? 'Deletion is refused while issues use this status.'
                                        : 'No issue uses this status.'}
                                </DialogDescription>
                            </DialogHeader>

                            {migrateIds.length > 0 ? (
                                <div className="grid gap-3.5">
                                    <p className="flex items-start gap-2.5 rounded-lg border border-destructive/35 bg-destructive/10 px-3.5 py-3 text-sm text-destructive">
                                        <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                                        <span>
                                            Move {migrateIds.length === 1 ? 'this issue' : `these ${migrateIds.length} issues`} to
                                            another status first, then delete “{deleting.name}”.
                                        </span>
                                    </p>
                                    <div className="grid gap-2">
                                        <Label>Move the issues to</Label>
                                        <Select
                                            value={migrateTo}
                                            onValueChange={(value) => {
                                                if (value) setMigrateTo(value);
                                            }}
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Choose a status" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {statuses
                                                    .filter((candidate) => candidate.id !== deleting.id)
                                                    .map((candidate) => (
                                                        <SelectItem key={candidate.id} value={candidate.id}>
                                                            {candidate.name}
                                                        </SelectItem>
                                                    ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    {migrationError ? (
                                        <p role="alert" className="text-sm text-destructive">
                                            {migrationError}
                                        </p>
                                    ) : null}
                                </div>
                            ) : null}

                            <DialogFooter>
                                <Button variant="ghost" onClick={() => setDeleting(null)}>
                                    Cancel
                                </Button>
                                <Button
                                    variant="outline"
                                    data-test="confirm-delete-status"
                                    className="border-destructive/35 text-destructive hover:bg-destructive/10"
                                    disabled={migrating}
                                    onClick={() => confirmDelete()}
                                >
                                    {migrateIds.length > 0 ? 'Move then delete' : 'Delete'}
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    ) : null}
                </Dialog>
            </SettingsShell>
        </AppLayout>
    );
}

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
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface StatusRow {
    id: string;
    name: string;
    category: string;
    position: number;
}

const CATEGORIES = [
    { value: 'backlog', label: 'Backlog' },
    { value: 'in_progress', label: 'In Progress' },
    { value: 'done', label: 'Done' },
    { value: 'cancelled', label: 'Cancelled' },
];

export default function Statuses() {
    const { statuses } = usePage<{ statuses: StatusRow[] }>().props;
    const { flash } = usePage<{ flash?: { status?: string } }>().props;
    const [editing, setEditing] = useState<StatusRow | null>(null);
    const [creating, setCreating] = useState(false);

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

    const remove = (status: StatusRow) => {
        if (!window.confirm(`Delete status "${status.name}"?`)) return;
        router.delete(route('statuses.destroy', status.id));
    };

    const move = (status: StatusRow, delta: number) => {
        const ids = statuses.map((s) => s.id);
        const index = ids.indexOf(status.id);
        const target = index + delta;
        if (target < 0 || target >= ids.length) return;
        [ids[index], ids[target]] = [ids[target], ids[index]];
        router.post(route('statuses.reorder'), { ids });
    };

    return (
        <AppLayout title="Statuses">
            <Head title="Statuses" />

            {flash?.status ? (
                <p className="mb-4 rounded-md border bg-muted/50 px-4 py-2 text-sm">{flash.status}</p>
            ) : null}

            <div className="mb-4 flex items-center justify-between">
                <p className="text-sm text-muted-foreground">
                    Status names are free; each belongs to a fixed semantic category.
                </p>
                <Button size="sm" onClick={() => setCreating(true)}>
                    New status
                </Button>
            </div>

            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead className="w-24">Order</TableHead>
                        <TableHead>Name</TableHead>
                        <TableHead>Category</TableHead>
                        <TableHead className="w-40 text-right">Actions</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {statuses.map((status) => (
                        <TableRow key={status.id}>
                            <TableCell>
                                <div className="flex gap-1">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={status.position === 0}
                                        onClick={() => move(status, -1)}
                                    >
                                        Up
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={status.position === statuses.length - 1}
                                        onClick={() => move(status, 1)}
                                    >
                                        Down
                                    </Button>
                                </div>
                            </TableCell>
                            <TableCell className="font-medium">{status.name}</TableCell>
                            <TableCell>
                                <Badge variant="outline">{status.category}</Badge>
                            </TableCell>
                            <TableCell className="text-right">
                                <div className="flex justify-end gap-1">
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
                                    <Button variant="ghost" size="sm" onClick={() => remove(status)}>
                                        Delete
                                    </Button>
                                </div>
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>

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
        </AppLayout>
    );
}

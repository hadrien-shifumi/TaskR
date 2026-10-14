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
import { MarkdownEditor } from '@/components/ui/markdown-editor';
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
import type { PageSharedProps } from '@/types';

interface TemplateRow {
    id: string;
    name: string;
    description: string | null;
    priority: string | null;
    labels: { id: string; name: string }[];
}

interface LabelOption {
    id: string;
    name: string;
    color: string;
}

const PRIORITIES = ['none', 'low', 'high', 'urgent'];

const emptyForm = { name: '', description: '', priority: 'none', label_ids: [] as string[] };

export default function Templates() {
    const { templates, labels } = usePage<{ templates: TemplateRow[]; labels: LabelOption[] }>().props;
    const { flash } = usePage<{ flash?: { status?: string } }>().props;
    const { workspace } = usePage<PageSharedProps>().props;
    const isOwner = workspace?.role === 'owner';

    const [editing, setEditing] = useState<TemplateRow | null>(null);
    const [creating, setCreating] = useState(false);

    const createForm = useForm({ ...emptyForm });
    const editForm = useForm({ ...emptyForm });

    const toggleLabel = (
        form: typeof createForm,
        labelId: string,
    ) => {
        const current = form.data.label_ids;
        form.setData(
            'label_ids',
            current.includes(labelId)
                ? current.filter((id) => id !== labelId)
                : [...current, labelId],
        );
    };

    const labelToggles = (
        form: typeof createForm,
        idPrefix: string,
    ) => (
        <div className="flex flex-wrap gap-1.5">
            {labels.length === 0 ? (
                <p className="text-sm text-muted-foreground">No labels in this workspace yet.</p>
            ) : null}
            {labels.map((label) => {
                const selected = form.data.label_ids.includes(label.id);
                return (
                    <Button
                        key={label.id}
                        id={`${idPrefix}-${label.id}`}
                        type="button"
                        variant={selected ? 'default' : 'outline'}
                        size="sm"
                        onClick={() => toggleLabel(form, label.id)}
                        aria-pressed={selected}
                    >
                        {label.name}
                    </Button>
                );
            })}
        </div>
    );

    const submitCreate = (e: React.FormEvent) => {
        e.preventDefault();
        createForm.post(route('templates.store'), {
            onSuccess: () => {
                setCreating(false);
                createForm.reset();
            },
        });
    };

    const submitEdit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!editing) return;
        editForm.patch(route('templates.update', editing.id), {
            onSuccess: () => setEditing(null),
        });
    };

    const remove = (template: TemplateRow) => {
        if (!window.confirm(`Delete template "${template.name}"? Existing issues are not affected.`)) return;
        router.delete(route('templates.destroy', template.id));
    };

    return (
        <AppLayout title="Templates">
            <Head title="Templates" />

            {flash?.status ? (
                <p className="mb-4 rounded-md border bg-muted/50 px-4 py-2 text-sm">{flash.status}</p>
            ) : null}

            <div className="mb-4 flex items-center justify-between">
                <p className="text-sm text-muted-foreground">
                    Non-blocking templates pre-fill new issues (description, priority, labels).
                </p>
                {isOwner ? (
                    <Button size="sm" onClick={() => setCreating(true)}>
                        New template
                    </Button>
                ) : null}
            </div>

            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Name</TableHead>
                        <TableHead>Priority</TableHead>
                        <TableHead>Labels</TableHead>
                        {isOwner ? <TableHead className="w-40 text-right">Actions</TableHead> : null}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {templates.map((template) => (
                        <TableRow key={template.id}>
                            <TableCell>
                                <span className="font-medium">{template.name}</span>
                                {template.description ? (
                                    <p className="line-clamp-1 text-sm text-muted-foreground">
                                        {template.description.split('\n')[0]}
                                    </p>
                                ) : null}
                            </TableCell>
                            <TableCell>
                                {template.priority && template.priority !== 'none' ? (
                                    <Badge variant="outline">{template.priority}</Badge>
                                ) : (
                                    <span className="text-muted-foreground">—</span>
                                )}
                            </TableCell>
                            <TableCell>
                                <div className="flex gap-1">
                                    {template.labels.map((label) => (
                                        <Badge key={label.id} variant="secondary">
                                            {label.name}
                                        </Badge>
                                    ))}
                                    {template.labels.length === 0 ? (
                                        <span className="text-muted-foreground">—</span>
                                    ) : null}
                                </div>
                            </TableCell>
                            {isOwner ? (
                                <TableCell className="text-right">
                                    <div className="flex justify-end gap-1">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => {
                                                setEditing(template);
                                                editForm.setData({
                                                    name: template.name,
                                                    description: template.description ?? '',
                                                    priority: template.priority ?? 'none',
                                                    label_ids: template.labels.map((label) => label.id),
                                                });
                                            }}
                                        >
                                            Edit
                                        </Button>
                                        <Button variant="ghost" size="sm" onClick={() => remove(template)}>
                                            Delete
                                        </Button>
                                    </div>
                                </TableCell>
                            ) : null}
                        </TableRow>
                    ))}
                    {templates.length === 0 ? (
                        <TableRow>
                            <TableCell colSpan={isOwner ? 4 : 3} className="py-6 text-center text-muted-foreground">
                                No templates yet.
                            </TableCell>
                        </TableRow>
                    ) : null}
                </TableBody>
            </Table>

            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>New template</DialogTitle>
                        <DialogDescription>
                            Pre-fill values applied when creating an issue. Everything stays editable.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submitCreate} className="grid gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="new-template-name">Name</Label>
                            <Input
                                id="new-template-name"
                                value={createForm.data.name}
                                onChange={(e) => createForm.setData('name', e.target.value)}
                                required
                            />
                            {createForm.errors.name ? (
                                <p className="text-sm text-destructive">{createForm.errors.name}</p>
                            ) : null}
                        </div>
                        <div className="grid gap-2">
                            <Label>Default description</Label>
                            <MarkdownEditor
                                value={createForm.data.description}
                                onChange={(markdown) => createForm.setData('description', markdown)}
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label>Default priority</Label>
                            <Select
                                value={createForm.data.priority}
                                onValueChange={(v) => {
                                    if (v) createForm.setData('priority', v);
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
                            <Label>Default labels</Label>
                            {labelToggles(createForm, 'new-template-label')}
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
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Edit template</DialogTitle>
                        <DialogDescription>
                            Changes never affect existing issues.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submitEdit} className="grid gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="edit-template-name">Name</Label>
                            <Input
                                id="edit-template-name"
                                value={editForm.data.name}
                                onChange={(e) => editForm.setData('name', e.target.value)}
                                required
                            />
                            {editForm.errors.name ? (
                                <p className="text-sm text-destructive">{editForm.errors.name}</p>
                            ) : null}
                        </div>
                        <div className="grid gap-2">
                            <Label>Default description</Label>
                            <MarkdownEditor
                                value={editForm.data.description}
                                onChange={(markdown) => editForm.setData('description', markdown)}
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label>Default priority</Label>
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
                            <Label>Default labels</Label>
                            {labelToggles(editForm, 'edit-template-label')}
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

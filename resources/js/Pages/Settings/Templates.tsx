import { FlashBanner } from '@/components/flash-banner';
import { FormDialog } from '@/components/form-dialog';
import { LabelPicker } from '@/components/label-picker';
import { PrioritySelect } from '@/components/priority-select';
import { PriorityBadge } from '@/components/issue-badges';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { MarkdownEditor } from '@/components/ui/markdown-editor';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { SettingsShell } from '@/components/settings-shell';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { MoreHorizontal, Pencil, Trash2 } from 'lucide-react';
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

/** Body pre-filled in the create dialog (maquette: "corps markdown prérempli"). */
const DEFAULT_BODY = '## Context\n\n## Steps to reproduce\n1.\n\n## Expected / Actual';

const emptyForm = { name: '', description: DEFAULT_BODY, priority: 'none', label_ids: [] as string[] };

export default function Templates() {
    const { templates, labels } = usePage<{ templates: TemplateRow[]; labels: LabelOption[] }>().props;
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

    const templateFields = (
        form: typeof createForm,
        idPrefix: string,
    ) => (
        <>
            <div className="grid gap-2">
                <Label htmlFor={`${idPrefix}-name`}>Name</Label>
                <Input
                    id={`${idPrefix}-name`}
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    required
                />
                {form.errors.name ? (
                    <p className="text-sm text-destructive">{form.errors.name}</p>
                ) : null}
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label>Default priority</Label>
                    <PrioritySelect
                        value={form.data.priority}
                        onValueChange={(priority) => form.setData('priority', priority)}
                    />
                </div>
                <div className="grid gap-2">
                    <Label>Default labels</Label>
                    <LabelPicker
                        labels={labels}
                        selectedIds={form.data.label_ids}
                        onToggle={(labelId) => toggleLabel(form, labelId)}
                        idPrefix={`${idPrefix}-label`}
                    />
                </div>
            </div>
            <div className="grid gap-2">
                <Label>Default description</Label>
                <MarkdownEditor
                    value={form.data.description}
                    onChange={(markdown) => form.setData('description', markdown)}
                />
            </div>
        </>
    );

    return (
        <AppLayout title="Settings" subtitle={workspace?.name} narrow>
            <SettingsShell
                title="Issue templates"
                description="Unique name per workspace · Markdown body · default priority and labels. Owner-only."
                actions={
                    <Button size="sm" onClick={() => setCreating(true)}>
                        New template
                    </Button>
                }
            >
                <Head title="Templates" />

                <FlashBanner />

                <div className="overflow-hidden rounded-xl border bg-card">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Template</TableHead>
                                <TableHead>Default priority</TableHead>
                                <TableHead>Labels</TableHead>
                                {isOwner ? <TableHead className="w-16" /> : null}
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
                                        <PriorityBadge priority={template.priority ?? 'none'} />
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex gap-1">
                                            {template.labels.map((label) => (
                                                <Badge
                                                    key={label.id}
                                                    variant="outline"
                                                    className="text-muted-foreground"
                                                >
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
                                            <DropdownMenu>
                                                <DropdownMenuTrigger
                                                    aria-label={`Actions for template ${template.name}`}
                                                    className="grid size-6.5 place-items-center rounded-md text-muted-foreground outline-none hover:bg-accent hover:text-foreground"
                                                >
                                                    <MoreHorizontal className="size-4" aria-hidden />
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end">
                                                    <DropdownMenuGroup>
                                                        <DropdownMenuItem
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
                                                            <Pencil aria-hidden />
                                                            Edit…
                                                        </DropdownMenuItem>
                                                        <DropdownMenuSeparator />
                                                        <DropdownMenuItem
                                                            variant="destructive"
                                                            onClick={() => remove(template)}
                                                        >
                                                            <Trash2 aria-hidden />
                                                            Delete…
                                                        </DropdownMenuItem>
                                                    </DropdownMenuGroup>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
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
                </div>

                <FormDialog
                    open={creating}
                    onOpenChange={setCreating}
                    title="New template"
                    description="Pre-fill values applied when creating an issue. Everything stays editable."
                    submitLabel="Create"
                    processing={createForm.processing}
                    contentClassName="max-h-[90vh] overflow-y-auto sm:max-w-2xl"
                    onSubmit={submitCreate}
                >
                    {templateFields(createForm, 'new-template')}
                </FormDialog>

                <FormDialog
                    open={editing !== null}
                    onOpenChange={(open) => {
                        if (!open) setEditing(null);
                    }}
                    title="Edit template"
                    description="Changes never affect existing issues."
                    submitLabel="Save"
                    processing={editForm.processing}
                    contentClassName="max-h-[90vh] overflow-y-auto sm:max-w-2xl"
                    onSubmit={submitEdit}
                >
                    {templateFields(editForm, 'edit-template')}
                </FormDialog>
            </SettingsShell>
        </AppLayout>
    );
}

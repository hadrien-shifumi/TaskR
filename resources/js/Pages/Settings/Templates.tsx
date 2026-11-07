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
// TipTap (the markdown editor's engine) is a heavy dependency: it rides a
// lazy chunk loaded on first editor open, not with the page bundle.
const MarkdownEditor = lazy(() =>
    import('@/components/ui/markdown-editor').then((m) => ({ default: m.MarkdownEditor })),
);
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
import { lazy, Suspense, useState } from 'react';
import { useTranslation } from 'react-i18next';
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

export default function Templates() {
    const { templates, labels } = usePage<{ templates: TemplateRow[]; labels: LabelOption[] }>().props;
    const { workspace } = usePage<PageSharedProps>().props;
    const { t } = useTranslation();
    const isOwner = workspace?.role === 'owner';

    const [editing, setEditing] = useState<TemplateRow | null>(null);
    const [creating, setCreating] = useState(false);

    /** Body pre-filled in the create dialog (maquette: "corps markdown prérempli"). */
    const emptyForm = {
        name: '',
        description: t('templatesPage.defaultBody'),
        priority: 'none',
        label_ids: [] as string[],
    };

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
        if (!window.confirm(t('templatesPage.deleteConfirm', { name: template.name }))) return;
        router.delete(route('templates.destroy', template.id));
    };

    const templateFields = (
        form: typeof createForm,
        idPrefix: string,
    ) => (
        <>
            <div className="grid gap-2">
                <Label htmlFor={`${idPrefix}-name`}>{t('common.name')}</Label>
                <Input
                    id={`${idPrefix}-name`}
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    required
                    aria-invalid={form.errors.name ? true : undefined}
                />
                {form.errors.name ? (
                    <p className="text-sm text-destructive">{form.errors.name}</p>
                ) : null}
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label>{t('templatesPage.defaultPriority')}</Label>
                    <PrioritySelect
                        value={form.data.priority}
                        onValueChange={(priority) => form.setData('priority', priority)}
                    />
                </div>
                <div className="grid gap-2">
                    <Label>{t('templatesPage.defaultLabels')}</Label>
                    <LabelPicker
                        labels={labels}
                        selectedIds={form.data.label_ids}
                        onToggle={(labelId) => toggleLabel(form, labelId)}
                        idPrefix={`${idPrefix}-label`}
                    />
                </div>
            </div>
            <div className="grid gap-2">
                <Label>{t('templatesPage.defaultDescription')}</Label>
                <Suspense
                    fallback={
                        <div className="min-h-48 animate-pulse rounded-lg border bg-muted/40" aria-hidden="true" />
                    }
                >
                    <MarkdownEditor
                        value={form.data.description}
                        onChange={(markdown) => form.setData('description', markdown)}
                    />
                </Suspense>
            </div>
        </>
    );

    return (
        <AppLayout title={t('settings.pageTitle')} subtitle={workspace?.name} narrow>
            <SettingsShell
                title={t('settings.sections.templates')}
                description={t('templatesPage.description')}
                actions={
                    <Button size="sm" onClick={() => setCreating(true)}>
                        {t('templatesPage.newTemplate')}
                    </Button>
                }
            >
                <Head title={t('settings.sections.templates')} />

                <FlashBanner />

                <div className="overflow-hidden rounded-xl border bg-card">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('templatesPage.columnTemplate')}</TableHead>
                                <TableHead>{t('templatesPage.columnDefaultPriority')}</TableHead>
                                <TableHead>{t('templatesPage.columnLabels')}</TableHead>
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
                                                    aria-label={t('templatesPage.templateAria', { name: template.name })}
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
                                                            {t('projectMenu.edit')}
                                                        </DropdownMenuItem>
                                                        <DropdownMenuSeparator />
                                                        <DropdownMenuItem
                                                            variant="destructive"
                                                            onClick={() => remove(template)}
                                                        >
                                                            <Trash2 aria-hidden />
                                                            {t('projectMenu.delete')}
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
                                        {t('templatesPage.noTemplates')}
                                    </TableCell>
                                </TableRow>
                            ) : null}
                        </TableBody>
                    </Table>
                </div>

                <FormDialog
                    open={creating}
                    onOpenChange={setCreating}
                    title={t('templatesPage.newTitle')}
                    description={t('templatesPage.newDescription')}
                    submitLabel={t('common.create')}
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
                    title={t('templatesPage.editTitle')}
                    description={t('templatesPage.editDescription')}
                    submitLabel={t('common.save')}
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

import { FormDialog } from '@/components/form-dialog';
import { LabelPicker } from '@/components/label-picker';
import { PrioritySelect } from '@/components/priority-select';
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
import { useForm } from '@inertiajs/react';
import type { LabelRef } from '@/types';
import type { TemplateOption } from '@/Pages/Issues/Index/types';

type ProjectOption = { id: string; name: string };
type StatusOption = { id: string; name: string; category: string };

type CreateIssueDialogProps = {
    open: boolean;
    onClose: () => void;
    projects: ProjectOption[];
    statuses: StatusOption[];
    labels: LabelRef[];
    templates: TemplateOption[];
};

/** Full-issue creation form: template pre-fill, fields and label toggles. */
export function CreateIssueDialog({ open, onClose, projects, statuses, labels, templates }: CreateIssueDialogProps) {
    const createForm = useForm({
        project_id: projects[0]?.id ?? '',
        title: '',
        description: '',
        status_id: '',
        priority: 'none',
        due_date: '',
        template: '',
        label_ids: [] as string[],
    });

    const createStatusId = createForm.data.status_id || statuses[0]?.id || '';

    const submitCreate = (e: React.FormEvent) => {
        e.preventDefault();
        createForm.post(route('issues.store'), {
            onSuccess: () => {
                onClose();
                createForm.reset();
            },
        });
    };

    /**
     * Client-side pre-fill: the template defaults fill description, priority
     * and labels so the user sees and edits them before submitting. The
     * selected template is sent along; the server-side merge then becomes a
     * no-op because every key is explicitly provided.
     */
    const applyTemplate = (templateId: string) => {
        const template = templates.find((t) => t.id === templateId);

        createForm.setData('template', template ? template.id : '');
        createForm.setData({
            description: template?.description ?? '',
            priority: template?.priority ?? 'none',
            label_ids: template ? template.labels.map((l) => l.id) : [],
        });
    };

    const toggleIssueLabel = (labelId: string) => {
        const current = createForm.data.label_ids;
        createForm.setData(
            'label_ids',
            current.includes(labelId)
                ? current.filter((id) => id !== labelId)
                : [...current, labelId],
        );
    };

    return (
        <FormDialog
            open={open}
            onOpenChange={(o) => {
                if (!o) onClose();
            }}
            title="New issue"
            description="Title is the only required field."
            submitLabel="Create"
            processing={createForm.processing}
            contentClassName="max-h-[90vh] overflow-y-auto sm:max-w-3xl"
            onSubmit={submitCreate}
        >
            {templates.length > 0 ? (
                <div className="grid gap-2">
                    <Label>Template</Label>
                    <Select
                        value={createForm.data.template || 'none'}
                        onValueChange={(v) => v && applyTemplate(v)}
                    >
                        <SelectTrigger>
                            <SelectValue>
                                {templates.find((t) => t.id === createForm.data.template)?.name ??
                                    'No template'}
                            </SelectValue>
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">No template</SelectItem>
                            {templates.map((t) => (
                                <SelectItem key={t.id} value={t.id}>
                                    {t.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <p className="text-sm text-muted-foreground">
                        Pre-fills description, priority and labels — everything stays editable.
                    </p>
                </div>
            ) : null}
            <div className="grid gap-2">
                <Label htmlFor="issue-title">Title</Label>
                <Input
                    id="issue-title"
                    value={createForm.data.title}
                    onChange={(e) => createForm.setData('title', e.target.value)}
                    aria-required="true"
                />
                {createForm.errors.title ? (
                    <p data-test="title-error" className="text-sm text-destructive">
                        {createForm.errors.title}
                    </p>
                ) : null}
            </div>
            <div className="grid gap-2">
                <Label>Description</Label>
                <MarkdownEditor
                    value={createForm.data.description}
                    onChange={(markdown) => createForm.setData('description', markdown)}
                />
            </div>
            <div className="grid grid-cols-2 gap-4">
                <div className="grid gap-2">
                    <Label>Project</Label>
                    <Select
                        value={createForm.data.project_id}
                        onValueChange={(v) => {
                            if (v) createForm.setData('project_id', v);
                        }}
                    >
                        <SelectTrigger>
                            <SelectValue>
                                {projects.find((p) => p.id === createForm.data.project_id)?.name ?? 'Project'}
                            </SelectValue>
                        </SelectTrigger>
                        <SelectContent>
                            {projects.map((p) => (
                                <SelectItem key={p.id} value={p.id}>
                                    {p.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {createForm.errors.project_id ? (
                        <p className="text-sm text-destructive">{createForm.errors.project_id}</p>
                    ) : null}
                </div>
                {statuses.length > 0 ? (
                    <div className="grid gap-2">
                        <Label>Status</Label>
                        <Select
                            value={createStatusId}
                            onValueChange={(v) => {
                                if (v) createForm.setData('status_id', v);
                            }}
                        >
                            <SelectTrigger>
                                <SelectValue>
                                    {statuses.find((s) => s.id === createStatusId)?.name ?? 'Status'}
                                </SelectValue>
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
                ) : null}
            </div>
            <div className="grid grid-cols-2 gap-4">
                <div className="grid gap-2">
                    <Label>Priority</Label>
                    <PrioritySelect
                        value={createForm.data.priority}
                        onValueChange={(priority) => createForm.setData('priority', priority)}
                    />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="issue-due">Due date</Label>
                    <Input
                        id="issue-due"
                        type="date"
                        value={createForm.data.due_date}
                        onChange={(e) => createForm.setData('due_date', e.target.value)}
                    />
                </div>
            </div>
            {labels.length > 0 ? (
                <div className="grid gap-2">
                    <Label>Labels</Label>
                    <LabelPicker
                        labels={labels}
                        selectedIds={createForm.data.label_ids}
                        onToggle={toggleIssueLabel}
                    />
                </div>
            ) : null}
        </FormDialog>
    );
}

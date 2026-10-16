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
import { router, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import type { LabelRef, UserRef } from '@/types';
import type { TemplateOption } from '@/Pages/Issues/Index/types';

type ProjectOption = { id: string; name: string };
type StatusOption = { id: string; name: string; category: string };

type BoardCreateIssueDialogProps = {
    open: boolean;
    onClose: () => void;
    /** The board's project — preselected on this screen. */
    project: ProjectOption;
    /** Every workspace status, in workspace order (the board columns). */
    statuses: StatusOption[];
    labels: LabelRef[];
    templates: TemplateOption[];
    members: UserRef[];
    /** Status preselected when the dialog opens from a column "+" button. */
    defaultStatusId: string | null;
};

/**
 * Full issue creation dialog of the board (maquette project-board.html,
 * dialog-lg): the project is preselected, the template pre-fills without
 * constraining, and every workspace status is offered. Labels, templates and
 * members are fed by the board page props; their sections hide while the
 * payload does not provide them yet.
 */
export function BoardCreateIssueDialog({
    open,
    onClose,
    project,
    statuses,
    labels,
    templates,
    members,
    defaultStatusId,
}: BoardCreateIssueDialogProps) {
    const { t } = useTranslation();
    const createForm = useForm({
        project_id: project.id,
        title: '',
        description: '',
        status_id: defaultStatusId ?? '',
        priority: 'none',
        due_date: '',
        template: '',
        label_ids: [] as string[],
        assignees: [] as string[],
        estimated_hours: '',
    });

    const createStatusId = createForm.data.status_id || statuses[0]?.id || '';
    const createStatus = statuses.find((status) => status.id === createStatusId);

    const submitCreate = (e: React.FormEvent) => {
        e.preventDefault();
        const estimatedHours = createForm.data.estimated_hours.trim();

        createForm.post(route('issues.store'), {
            onSuccess: (page) => {
                onClose();
                createForm.reset();

                // The estimate is not part of issue creation: it rides the
                // dedicated route once the issue exists. Creation redirects
                // to the issue page, whose URL carries the new id.
                const match = /\/issues\/([^/?]+)/.exec(page.url);
                if (match !== null && estimatedHours !== '') {
                    router.post(route('issues.estimate', match[1]), {
                        estimated_hours: estimatedHours,
                    });
                }
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

    const toggleAssignee = (userId: string) => {
        const current = createForm.data.assignees;
        createForm.setData(
            'assignees',
            current.includes(userId)
                ? current.filter((id) => id !== userId)
                : [...current, userId],
        );
    };

    return (
        <FormDialog
            open={open}
            onOpenChange={(o) => {
                if (!o) onClose();
            }}
            title={t('issues.newIssue')}
            description={t('projectBoard.boardDialogDescription')}
            submitLabel={t('issues.create')}
            processing={createForm.processing}
            contentClassName="max-h-[90vh] overflow-y-auto sm:max-w-3xl"
            onSubmit={submitCreate}
        >
            <div className="grid gap-2">
                <Label htmlFor="board-issue-title">{t('issues.fields.title')}</Label>
                <Input
                    id="board-issue-title"
                    placeholder={t('issues.quickTitlePlaceholder')}
                    value={createForm.data.title}
                    onChange={(e) => createForm.setData('title', e.target.value)}
                    aria-required="true"
                    autoFocus
                    aria-invalid={createForm.errors.title ? true : undefined}
                />
                {createForm.errors.title ? (
                    <p data-test="board-title-error" className="text-sm text-destructive">
                        {createForm.errors.title}
                    </p>
                ) : null}
            </div>
            <div className="grid grid-cols-2 gap-4">
                <div className="grid gap-2">
                    <Label>{t('issues.fields.project')}</Label>
                    <Select
                        value={createForm.data.project_id}
                        onValueChange={(v) => {
                            if (v) createForm.setData('project_id', v);
                        }}
                    >
                        <SelectTrigger
                            aria-label={t('issues.fields.project')}
                            aria-invalid={createForm.errors.project_id ? true : undefined}
                        >
                            <SelectValue>{project.name}</SelectValue>
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={project.id}>{project.name}</SelectItem>
                        </SelectContent>
                    </Select>
                    {createForm.errors.project_id ? (
                        <p className="text-sm text-destructive">{createForm.errors.project_id}</p>
                    ) : null}
                </div>
                {templates.length > 0 ? (
                    <div className="grid gap-2">
                        <Label>{t('issues.template')}</Label>
                        <Select
                            value={createForm.data.template || 'none'}
                            onValueChange={(v) => v && applyTemplate(v)}
                        >
                            <SelectTrigger>
                                <SelectValue>
                                    {templates.find((t) => t.id === createForm.data.template)?.name ??
                                        t('issues.noTemplate')}
                                </SelectValue>
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">{t('issues.noTemplate')}</SelectItem>
                                {templates.map((t) => (
                                    <SelectItem key={t.id} value={t.id}>
                                        {t.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <p className="text-sm text-muted-foreground">
                            {t('projectBoard.boardTemplateHint')}
                        </p>
                    </div>
                ) : null}
            </div>
            <div className="grid gap-2">
                <Label>{t('issues.fields.description')}</Label>
                <MarkdownEditor
                    value={createForm.data.description}
                    onChange={(markdown) => createForm.setData('description', markdown)}
                />
            </div>
            <div className="grid grid-cols-2 gap-4">
                <div className="grid gap-2">
                    <Label>{t('issues.fields.status')}</Label>
                    <Select
                        value={createStatusId}
                        onValueChange={(v) => {
                            if (v) createForm.setData('status_id', v);
                        }}
                    >
                        <SelectTrigger data-test="board-issue-status">
                            <SelectValue>
                                {createStatus
                                    ? `${createStatus.name} · ${t(`statusCategory.${createStatus.category}`)}`
                                    : t('issues.fields.status')}
                            </SelectValue>
                        </SelectTrigger>
                        <SelectContent>
                            {statuses.map((status) => (
                                <SelectItem key={status.id} value={status.id}>
                                    {status.name} · {t(`statusCategory.${status.category}`)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <div className="grid gap-2">
                    <Label>{t('issues.fields.priority')}</Label>
                    <PrioritySelect
                        value={createForm.data.priority}
                        onValueChange={(priority) => createForm.setData('priority', priority)}
                    />
                </div>
            </div>
            {labels.length > 0 || members.length > 0 ? (
                <div className="grid grid-cols-2 gap-4">
                    {labels.length > 0 ? (
                        <div className="grid gap-2">
                            <Label>{t('issues.fields.labels')}</Label>
                            <LabelPicker
                                labels={labels}
                                selectedIds={createForm.data.label_ids}
                                onToggle={toggleIssueLabel}
                            />
                        </div>
                    ) : null}
                    {members.length > 0 ? (
                        <div className="grid gap-2">
                            <Label>{t('columns.assignees')}</Label>
                            <div className="flex flex-wrap gap-2">
                                {members.map((member) => {
                                    const selected = createForm.data.assignees.includes(member.id);
                                    return (
                                        <button
                                            key={member.id}
                                            type="button"
                                            aria-pressed={selected}
                                            onClick={() => toggleAssignee(member.id)}
                                            className={`rounded-md border px-2 py-1 text-xs ${
                                                selected
                                                    ? 'border-primary bg-primary text-primary-foreground'
                                                    : 'bg-background text-muted-foreground'
                                            }`}
                                        >
                                            {member.name}
                                        </button>
                                    );
                                })}
                            </div>
                            <p className="text-sm text-muted-foreground">{t('projectBoard.severalAssignees')}</p>
                        </div>
                    ) : null}
                </div>
            ) : null}
            <div className="grid grid-cols-2 gap-4">
                <div className="grid gap-2">
                    <Label htmlFor="board-issue-due">{t('issues.fields.dueDate')}</Label>
                    <Input
                        id="board-issue-due"
                        type="date"
                        value={createForm.data.due_date}
                        onChange={(e) => createForm.setData('due_date', e.target.value)}
                    />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="board-issue-estimate">{t('projectBoard.estimateHours')}</Label>
                    <Input
                        id="board-issue-estimate"
                        type="number"
                        step="0.5"
                        min="0"
                        placeholder={t('projectBoard.estimatePlaceholder')}
                        value={createForm.data.estimated_hours}
                        onChange={(e) => createForm.setData('estimated_hours', e.target.value)}
                    />
                </div>
            </div>
        </FormDialog>
    );
}

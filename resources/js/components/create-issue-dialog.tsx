import { FormDialog } from "@/components/form-dialog";
import { LabelPicker } from "@/components/label-picker";
import { PrioritySelect } from "@/components/priority-select";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { MarkdownEditor } from "@/components/ui/markdown-editor";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import { useForm } from "@inertiajs/react";
import { useTranslation } from "react-i18next";
import type { LabelRef } from "@/types";
import type { TemplateOption } from "@/Pages/Issues/Index/types";

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

/**
 * Full-issue creation form: template pre-fill, fields and label toggles.
 *
 * Wide two-column layout: the writing side (template, title, description)
 * fills the left column and is the only scrollable area; the properties
 * (project, status, priority, due date, labels) sit in a fixed right column.
 * Header and footer stay pinned to the panel. Body rows are `auto` and the
 * grid content starts at the top: stretching them would spread the fields
 * apart and push the description editor away from its label.
 */
export function CreateIssueDialog({
    open,
    onClose,
    projects,
    statuses,
    labels,
    templates,
}: CreateIssueDialogProps) {
    const { t } = useTranslation();
    const createForm = useForm({
        project_id: projects[0]?.id ?? "",
        title: "",
        description: "",
        status_id: "",
        priority: "none",
        due_date: "",
        template: "",
        label_ids: [] as string[],
    });

    const createStatusId = createForm.data.status_id || statuses[0]?.id || "";

    const submitCreate = (e: React.FormEvent) => {
        e.preventDefault();
        createForm.post(route("issues.store"), {
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

        // Functional form: in Inertia v3 the object form of setData REPLACES
        // the whole data object, so merging over the previous state keeps the
        // untouched fields (title, project, status, due date) intact.
        createForm.setData((data) => ({
            ...data,
            template: template ? template.id : "",
            description: template?.description ?? "",
            priority: template?.priority ?? "none",
            label_ids: template ? template.labels.map((l) => l.id) : [],
        }));
    };

    const toggleIssueLabel = (labelId: string) => {
        const current = createForm.data.label_ids;
        createForm.setData(
            "label_ids",
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
            title={t("issues.newIssue")}
            description={t("issues.titleOnlyRequired")}
            submitLabel={t("common.create")}
            processing={createForm.processing}
            contentClassName="max-h-[90vh] sm:max-w-[min(1400px,calc(100vw-2rem))]"
            bodyClassName="grid auto-rows-min content-start gap-6 md:grid-cols-[minmax(0,1fr)_minmax(280px,360px)]"
            scrollableBody
            onSubmit={submitCreate}
        >
            <div data-test="issue-redaction" className="flex min-h-0 flex-col gap-4">
                {templates.length > 0 ? (
                    <div className="grid gap-2">
                        <Label>{t("issues.template")}</Label>
                        <Select
                            value={createForm.data.template || "none"}
                            onValueChange={(v) => v && applyTemplate(v)}
                        >
                            <SelectTrigger>
                                <SelectValue>
                                    {templates.find(
                                        (t) =>
                                            t.id === createForm.data.template,
                                    )?.name ?? t("issues.noTemplate")}
                                </SelectValue>
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">
                                    {t("issues.noTemplate")}
                                </SelectItem>
                                {templates.map((t) => (
                                    <SelectItem key={t.id} value={t.id}>
                                        {t.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <p className="text-sm text-muted-foreground">
                            {t("issues.templateHint")}
                        </p>
                    </div>
                ) : null}
                <div className="grid gap-2">
                    <Label htmlFor="issue-title">
                        {t("issues.fields.title")}
                    </Label>
                    <Input
                        id="issue-title"
                        value={createForm.data.title}
                        onChange={(e) =>
                            createForm.setData("title", e.target.value)
                        }
                        aria-required="true"
                        aria-invalid={
                            createForm.errors.title ? true : undefined
                        }
                    />
                    {createForm.errors.title ? (
                        <p
                            data-test="title-error"
                            className="text-sm text-destructive"
                        >
                            {createForm.errors.title}
                        </p>
                    ) : null}
                </div>
                {/* Flex column, not grid: a grid stretches its auto rows to
                    fill the block (align-content defaults to stretch), which
                    leaves a dead band around the "Description" label. Here the
                    label keeps its natural height and the editor (fill) takes
                    the remaining space. */}
                <div
                    data-test="issue-description"
                    className="flex min-h-64 flex-1 flex-col gap-2"
                >
                    <Label>{t("issues.fields.description")}</Label>
                    <MarkdownEditor
                        fill
                        value={createForm.data.description}
                        onChange={(markdown) =>
                            createForm.setData("description", markdown)
                        }
                    />
                </div>
            </div>
            <div
                data-test="issue-properties"
                className="grid auto-rows-min content-start gap-4"
            >
                <div className="grid gap-2">
                    <Label>{t("issues.fields.project")}</Label>
                    <Select
                        value={createForm.data.project_id}
                        onValueChange={(v) => {
                            if (v) createForm.setData("project_id", v);
                        }}
                    >
                        <SelectTrigger
                            aria-invalid={
                                createForm.errors.project_id ? true : undefined
                            }
                        >
                            <SelectValue>
                                {projects.find(
                                    (p) => p.id === createForm.data.project_id,
                                )?.name ?? t("issues.fields.project")}
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
                        <p className="text-sm text-destructive">
                            {createForm.errors.project_id}
                        </p>
                    ) : null}
                </div>
                {statuses.length > 0 ? (
                    <div className="grid gap-2">
                        <Label>{t("issues.fields.status")}</Label>
                        <Select
                            value={createStatusId}
                            onValueChange={(v) => {
                                if (v) createForm.setData("status_id", v);
                            }}
                        >
                            <SelectTrigger>
                                <SelectValue>
                                    {statuses.find(
                                        (s) => s.id === createStatusId,
                                    )?.name ?? t("issues.fields.status")}
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
                <div className="grid gap-2">
                    <Label>{t("issues.fields.priority")}</Label>
                    <PrioritySelect
                        value={createForm.data.priority}
                        onValueChange={(priority) =>
                            createForm.setData("priority", priority)
                        }
                    />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="issue-due">
                        {t("issues.fields.dueDate")}
                    </Label>
                    <Input
                        id="issue-due"
                        type="date"
                        value={createForm.data.due_date}
                        onChange={(e) =>
                            createForm.setData("due_date", e.target.value)
                        }
                    />
                </div>
                {labels.length > 0 ? (
                    <div className="grid gap-2">
                        <Label>{t("issues.fields.labels")}</Label>
                        <LabelPicker
                            labels={labels}
                            selectedIds={createForm.data.label_ids}
                            onToggle={toggleIssueLabel}
                        />
                    </div>
                ) : null}
            </div>
        </FormDialog>
    );
}

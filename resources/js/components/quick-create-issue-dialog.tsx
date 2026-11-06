import { FormDialog } from '@/components/form-dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

type ProjectOption = { id: string; name: string };

type QuickCreateIssueDialogProps = {
    open: boolean;
    onClose: () => void;
    projects: ProjectOption[];
};

/**
 * Minimal issue creation (home pagebar, maquette home.html): a title and a
 * project are enough — the default status and the none priority are applied
 * server-side, everything else stays editable after creation.
 */
export function QuickCreateIssueDialog({ open, onClose, projects }: QuickCreateIssueDialogProps) {
    const { t } = useTranslation();
    const form = useForm({
        title: '',
        project_id: projects[0]?.id ?? '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(route('issues.store'), {
            onSuccess: () => {
                onClose();
                form.reset();
            },
        });
    };

    return (
        <FormDialog
            open={open}
            onOpenChange={(o) => {
                if (!o) onClose();
            }}
            title={t('issues.newIssue')}
            description={t('issues.quickDescription')}
            submitLabel={t('issues.create')}
            processing={form.processing}
            contentClassName="sm:max-w-lg"
            onSubmit={submit}
        >
            <div className="grid gap-2">
                <Label htmlFor="quick-issue-title">{t('issues.fields.title')}</Label>
                <Input
                    id="quick-issue-title"
                    placeholder={t('issues.quickTitlePlaceholder')}
                    aria-required="true"
                    autoFocus
                    value={form.data.title}
                    onChange={(e) => form.setData('title', e.target.value)}
                    aria-invalid={form.errors.title ? true : undefined}
                />
                {form.errors.title ? (
                    <p data-test="quick-title-error" className="text-sm text-destructive">
                        {form.errors.title}
                    </p>
                ) : null}
            </div>
            <div className="grid gap-2">
                <Label>{t('issues.fields.project')}</Label>
                <Select
                    value={form.data.project_id}
                    onValueChange={(v) => {
                        if (v) form.setData('project_id', v);
                    }}
                >
                    <SelectTrigger
                        aria-label={t('issues.fields.project')}
                        aria-invalid={form.errors.project_id ? true : undefined}
                    >
                        <SelectValue>
                            {projects.find((p) => p.id === form.data.project_id)?.name ?? t('issues.fields.project')}
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
                {form.errors.project_id ? (
                    <p className="text-sm text-destructive">{form.errors.project_id}</p>
                ) : null}
            </div>
            <p className="text-sm text-muted-foreground">
                {t('issues.quickFooter')}
            </p>
        </FormDialog>
    );
}

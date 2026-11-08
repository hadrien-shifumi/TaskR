import { FormDialog } from '@/components/form-dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import type { MilestoneCard } from '@/types';

type MilestoneFormDialogProps = {
    open: boolean;
    onClose: () => void;
    /** Project of the creation dialog — ignored in edit mode. */
    project: { id: string; name: string };
    /** The milestone being edited, or null in creation mode. */
    milestone?: MilestoneCard | null;
};

/**
 * Create and edit dialog of a milestone (maquette pattern of the issue
 * dialogs): title, description and the date window. Creation posts to
 * the project's milestone route, edition patches the milestone — both
 * redirect back with a flash, so the dialog simply closes on success.
 */
export function MilestoneFormDialog({ open, onClose, project, milestone = null }: MilestoneFormDialogProps) {
    const { t } = useTranslation();
    const form = useForm({
        title: milestone?.title ?? '',
        description: milestone?.description ?? '',
        start_date: milestone?.startDate ?? '',
        target_date: milestone?.targetDate ?? '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (milestone !== null) {
            form.patch(route('milestones.update', milestone.id), { onSuccess: () => onClose() });
            return;
        }

        form.post(route('milestones.store', project.id), { onSuccess: () => onClose() });
    };

    return (
        <FormDialog
            open={open}
            onOpenChange={(o) => {
                if (!o) onClose();
            }}
            title={milestone !== null ? t('milestones.form.editTitle') : t('milestones.form.createTitle')}
            description={
                milestone !== null ? t('milestones.form.editDescription') : t('milestones.form.createDescription')
            }
            submitLabel={milestone !== null ? t('common.save') : t('common.create')}
            processing={form.processing}
            onSubmit={submit}
        >
            <div className="grid gap-2">
                <Label htmlFor="milestone-title">{t('milestones.form.title')}</Label>
                <Input
                    id="milestone-title"
                    data-test="milestone-title"
                    value={form.data.title}
                    onChange={(e) => form.setData('title', e.target.value)}
                    autoFocus
                    aria-invalid={form.errors.title ? true : undefined}
                />
                {form.errors.title ? <p className="text-sm text-destructive">{form.errors.title}</p> : null}
            </div>
            <div className="grid gap-2">
                <Label htmlFor="milestone-description">{t('milestones.form.description')}</Label>
                <Textarea
                    id="milestone-description"
                    data-test="milestone-description"
                    value={form.data.description}
                    onChange={(e) => form.setData('description', e.target.value)}
                />
                {form.errors.description ? (
                    <p className="text-sm text-destructive">{form.errors.description}</p>
                ) : null}
            </div>
            <div className="grid grid-cols-2 gap-4">
                <div className="grid gap-2">
                    <Label htmlFor="milestone-start">{t('milestones.form.startDate')}</Label>
                    <Input
                        id="milestone-start"
                        data-test="milestone-start"
                        type="date"
                        value={form.data.start_date}
                        onChange={(e) => form.setData('start_date', e.target.value)}
                        aria-invalid={form.errors.start_date ? true : undefined}
                    />
                    {form.errors.start_date ? (
                        <p className="text-sm text-destructive">{form.errors.start_date}</p>
                    ) : null}
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="milestone-target">{t('milestones.form.targetDate')}</Label>
                    <Input
                        id="milestone-target"
                        data-test="milestone-target"
                        type="date"
                        value={form.data.target_date}
                        onChange={(e) => form.setData('target_date', e.target.value)}
                        aria-invalid={form.errors.target_date ? true : undefined}
                    />
                    {form.errors.target_date ? (
                        <p className="text-sm text-destructive">{form.errors.target_date}</p>
                    ) : null}
                </div>
            </div>
        </FormDialog>
    );
}

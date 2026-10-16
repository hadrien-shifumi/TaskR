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
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import type { IssueDetail } from '../types';

type EditDatesDialogProps = {
    issue: IssueDetail;
    open: boolean;
    onClose: () => void;
};

/**
 * Maquette dlg-edit-dates: due date and estimation in one dialog. The due
 * date rides the issue update (which rewrites every field it knows, so the
 * untouched ones travel along), the estimate its dedicated action — chained,
 * then the dialog closes.
 */
export function EditDatesDialog({ issue, open, onClose }: EditDatesDialogProps) {
    return (
        <Dialog
            open={open}
            onOpenChange={(o) => {
                if (!o) onClose();
            }}
        >
            {open ? <EditDatesForm issue={issue} onClose={onClose} /> : null}
        </Dialog>
    );
}

function EditDatesForm({ issue, onClose }: { issue: IssueDetail; onClose: () => void }) {
    const { t } = useTranslation();
    const datesForm = useForm({
        title: issue.title,
        description: issue.description ?? '',
        priority: issue.priority,
        due_date: issue.dueDate ? issue.dueDate.slice(0, 10) : '',
        estimated_hours: issue.estimatedHours !== null ? String(issue.estimatedHours) : '',
    });

    const submitDates = (e: React.FormEvent) => {
        e.preventDefault();
        datesForm.patch(route('issues.update', issue.id), {
            onSuccess: () => {
                datesForm.post(route('issues.estimate', issue.id), {
                    onSuccess: () => onClose(),
                });
            },
        });
    };

    return (
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{t('issueShow.datesTitle')}</DialogTitle>
                <DialogDescription>{t('issueShow.bothOptional')}</DialogDescription>
            </DialogHeader>
            <form onSubmit={submitDates} className="grid gap-4">
                <div className="grid grid-cols-2 gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="edit-due-date">{t('issues.fields.dueDate')}</Label>
                        <Input
                            id="edit-due-date"
                            data-test="dates-due"
                            type="date"
                            value={datesForm.data.due_date}
                            onChange={(e) => datesForm.setData('due_date', e.target.value)}
                            aria-invalid={datesForm.errors.due_date ? true : undefined}
                        />
                        {datesForm.errors.due_date ? (
                            <p className="text-sm text-destructive">{datesForm.errors.due_date}</p>
                        ) : null}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="edit-estimate">{t('issueShow.estimationHours')}</Label>
                        <Input
                            id="edit-estimate"
                            data-test="dates-estimate"
                            type="number"
                            step="0.5"
                            min="0"
                            value={datesForm.data.estimated_hours}
                            onChange={(e) => datesForm.setData('estimated_hours', e.target.value)}
                            aria-invalid={datesForm.errors.estimated_hours ? true : undefined}
                        />
                        {datesForm.errors.estimated_hours ? (
                            <p className="text-sm text-destructive">{datesForm.errors.estimated_hours}</p>
                        ) : null}
                    </div>
                </div>
                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button type="submit" data-test="dates-save" disabled={datesForm.processing}>
                        {t('common.save')}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}

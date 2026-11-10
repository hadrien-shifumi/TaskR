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

type SetEstimateDialogProps = {
    issue: IssueDetail;
    open: boolean;
    onClose: () => void;
};

/**
 * Maquette dlg-set-estimate: the estimation alone, on the pattern of the
 * other prop dialogs. One hours field — empty clears the estimate, a value
 * posts to the dedicated estimate action. A 422 refusal surfaces inside
 * the dialog; the fiche stays untouched.
 */
export function SetEstimateDialog({ issue, open, onClose }: SetEstimateDialogProps) {
    return (
        <Dialog
            open={open}
            onOpenChange={(o) => {
                if (!o) onClose();
            }}
        >
            {open ? <SetEstimateForm issue={issue} onClose={onClose} /> : null}
        </Dialog>
    );
}

function SetEstimateForm({ issue, onClose }: { issue: IssueDetail; onClose: () => void }) {
    const { t } = useTranslation();
    const estimateForm = useForm({
        estimated_hours: issue.estimatedHours !== null ? String(issue.estimatedHours) : '',
    });

    const setEstimate = (e: React.FormEvent) => {
        e.preventDefault();
        estimateForm.post(route('issues.estimate', issue.id), {
            onSuccess: () => onClose(),
            onHttpException: () => {
                estimateForm.setError(
                    'estimated_hours',
                    t('issueShow.estimateRefused') + ' ' + t('issueShow.estimateError'),
                );
                return false;
            },
        });
    };

    return (
        <DialogContent data-test="estimate-dialog">
            <DialogHeader>
                <DialogTitle>{t('issueShow.estimateTitle')}</DialogTitle>
                <DialogDescription>{t('issueShow.estimateDialogHint')}</DialogDescription>
            </DialogHeader>
            <form onSubmit={setEstimate} className="grid gap-4">
                <div className="grid gap-2">
                    <Label htmlFor="estimate-input">{t('issueShow.estimationHours')}</Label>
                    <Input
                        id="estimate-input"
                        data-test="estimate-input"
                        type="number"
                        step="0.5"
                        min="0"
                        value={estimateForm.data.estimated_hours}
                        onChange={(e) => estimateForm.setData('estimated_hours', e.target.value)}
                        aria-invalid={estimateForm.errors.estimated_hours ? true : undefined}
                    />
                    {estimateForm.errors.estimated_hours ? (
                        <p className="text-sm text-destructive">{estimateForm.errors.estimated_hours}</p>
                    ) : null}
                </div>
                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button type="submit" data-test="estimate-submit" disabled={estimateForm.processing}>
                        {t('common.save')}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}

import { Banner } from '@/components/banner';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { IssueDetail } from '../types';

type SetParentDialogProps = {
    issue: IssueDetail;
    candidates: { id: string; title: string }[];
    open: boolean;
    onClose: () => void;
};

/**
 * Maquette dlg-set-parent: same-workspace candidates only, cycles refused.
 * The cycle rule lives in the action (ADR-5); the 422 refusal surfaces as a
 * banner-danger inside the dialog.
 */
export function SetParentDialog({ issue, candidates, open, onClose }: SetParentDialogProps) {
    return (
        <Dialog
            open={open}
            onOpenChange={(o) => {
                if (!o) onClose();
            }}
        >
            {open ? <SetParentForm issue={issue} candidates={candidates} onClose={onClose} /> : null}
        </Dialog>
    );
}

function SetParentForm({
    issue,
    candidates,
    onClose,
}: {
    issue: IssueDetail;
    candidates: { id: string; title: string }[];
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const [parentId, setParentId] = useState<string>(issue.parent?.id ?? 'none');
    const [cycleError, setCycleError] = useState<string | null>(null);

    const setParent = (e: React.FormEvent) => {
        e.preventDefault();
        setCycleError(null);
        router.post(
            route('issues.parent', issue.id),
            { parent_id: parentId === 'none' ? null : parentId },
            {
                onSuccess: () => onClose(),
                onHttpException: (response) => {
                    setCycleError(
                        response.status === 422
                            ? t('issueShow.cycleError')
                            : t('issueShow.setError'),
                    );
                    return false;
                },
            },
        );
    };

    return (
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{t('issueShow.parentTitle')}</DialogTitle>
                <DialogDescription>{t('issueShow.parentHint')}</DialogDescription>
            </DialogHeader>
            <form onSubmit={setParent} className="grid gap-4">
                <div className="grid gap-2">
                    <Label htmlFor="parent-select">{t('issueShow.parentIssue')}</Label>
                    <Select
                        value={parentId}
                        onValueChange={(value) => {
                            if (value) {
                                setParentId(value);
                                setCycleError(null);
                            }
                        }}
                    >
                        <SelectTrigger id="parent-select" data-test="parent-select" className="w-full">
                            <SelectValue>
                                {parentId === 'none'
                                    ? t('issueShow.noParent')
                                    : (candidates.find((c) => c.id === parentId)?.title ?? t('issueShow.noParent'))}
                            </SelectValue>
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">{t('issueShow.noParent')}</SelectItem>
                            {candidates.map((candidate) => (
                                <SelectItem key={candidate.id} value={candidate.id}>
                                    {candidate.title}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                {cycleError ? (
                    <Banner variant="danger" role="alert" data-test="cycle-error">
                        <strong>{t('issueShow.cycleRefused')}</strong> {cycleError}
                    </Banner>
                ) : null}
                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button type="submit" data-test="parent-submit">
                        {t('issueShow.setParent')}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}

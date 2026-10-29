import { Banner } from '@/components/banner';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { IssueDetail } from '../types';

type SetDependenciesDialogProps = {
    issue: IssueDetail;
    candidates: { id: string; title: string }[];
    open: boolean;
    onClose: () => void;
};

/**
 * Maquette dlg-set-dependencies: multi-select of the same-workspace
 * candidates, pre-checked with the current blockers. Submit syncs the
 * whole blocker set (`blocked_by`); a 422 refusal (self or cycle)
 * surfaces as a banner-danger inside the dialog.
 */
export function SetDependenciesDialog({ issue, candidates, open, onClose }: SetDependenciesDialogProps) {
    return (
        <Dialog
            open={open}
            onOpenChange={(o) => {
                if (!o) onClose();
            }}
        >
            {open ? (
                <SetDependenciesForm issue={issue} candidates={candidates} onClose={onClose} />
            ) : null}
        </Dialog>
    );
}

function SetDependenciesForm({
    issue,
    candidates,
    onClose,
}: {
    issue: IssueDetail;
    candidates: { id: string; title: string }[];
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const [selectedIds, setSelectedIds] = useState<string[]>(
        issue.blockedBy.map((blocker) => blocker.id),
    );
    const [cycleError, setCycleError] = useState<string | null>(null);

    const toggle = (candidateId: string, checked: boolean) => {
        setCycleError(null);
        setSelectedIds((current) =>
            checked ? [...current, candidateId] : current.filter((id) => id !== candidateId),
        );
    };

    const save = (e: React.FormEvent) => {
        e.preventDefault();
        setCycleError(null);
        router.post(
            route('issues.dependencies', issue.id),
            { blocked_by: selectedIds },
            {
                onSuccess: () => onClose(),
                onHttpException: (response) => {
                    setCycleError(
                        response.status === 422
                            ? t('issueShow.dependencyCycleError')
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
                <DialogTitle>{t('issueShow.dependenciesTitle')}</DialogTitle>
                <DialogDescription>{t('issueShow.dependenciesDialogHint')}</DialogDescription>
            </DialogHeader>
            <form onSubmit={save} className="grid gap-4">
                <div className="grid gap-2">
                    <Label>{t('issueShow.blockedBy')}</Label>
                    <div
                        data-test="dependency-candidates"
                        className="grid max-h-64 gap-1 overflow-y-auto rounded-lg border border-border p-2"
                    >
                        {candidates.map((candidate) => (
                            <Label
                                key={candidate.id}
                                className="flex cursor-pointer items-center gap-2.5 rounded-md px-2 py-1.5 text-sm font-normal hover:bg-muted"
                            >
                                <Checkbox
                                    checked={selectedIds.includes(candidate.id)}
                                    onCheckedChange={(checked) => toggle(candidate.id, checked === true)}
                                    data-test="dependency-candidate"
                                />
                                <span className="min-w-0 truncate">{candidate.title}</span>
                            </Label>
                        ))}
                    </div>
                </div>
                {cycleError ? (
                    <Banner variant="danger" role="alert" data-test="dependency-cycle-error">
                        <strong>{t('issueShow.dependencyCycleRefused')}</strong> {cycleError}
                    </Banner>
                ) : null}
                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button type="submit" data-test="dependency-submit">
                        {t('issueShow.saveDependencies')}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}

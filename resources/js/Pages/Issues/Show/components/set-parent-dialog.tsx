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
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ParentAutocomplete, type ParentSearchResult } from '@/components/parent-autocomplete';
import type { IssueDetail } from '../types';

type SetParentDialogProps = {
    issue: IssueDetail;
    open: boolean;
    onClose: () => void;
};

/**
 * Maquette dlg-set-parent: live title search feeds the parent autocomplete
 * (debounced, LIKE server-side); the cycle rule lives in the action (ADR-5)
 * and the 422 refusal surfaces as a banner-danger inside the dialog.
 */
export function SetParentDialog({ issue, open, onClose }: SetParentDialogProps) {
    return (
        <Dialog
            open={open}
            onOpenChange={(o) => {
                if (!o) onClose();
            }}
        >
            {open ? <SetParentForm issue={issue} onClose={onClose} /> : null}
        </Dialog>
    );
}

function SetParentForm({ issue, onClose }: { issue: IssueDetail; onClose: () => void }) {
    const { t } = useTranslation();
    const [parentId, setParentId] = useState<string>(issue.parent?.id ?? 'none');
    const [cycleError, setCycleError] = useState<string | null>(null);

    const handleChange = (id: string) => {
        setParentId(id);
        setCycleError(null);
    };

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

    const searchIssues = (q: string): Promise<ParentSearchResult[]> =>
        fetch(route('issues.search', { q, limit: 10 }), {
            headers: { Accept: 'application/json' },
        })
            .then((response) => (response.ok ? response.json() : Promise.reject(response.status)))
            .then((payload: ParentSearchResult[]) => payload)
            .catch(() => []);

    return (
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{t('issueShow.parentTitle')}</DialogTitle>
                <DialogDescription>{t('issueShow.parentHint')}</DialogDescription>
            </DialogHeader>
            <form onSubmit={setParent} className="grid gap-4">
                <ParentAutocomplete
                    value={parentId}
                    onChange={handleChange}
                    currentTitle={issue.parent?.title ?? null}
                    search={searchIssues}
                    excludeIds={[issue.id]}
                    labelKey="issueShow.parentIssue"
                    placeholderKey="issueShow.parentSearchPlaceholder"
                    emptyKey="issueShow.parentSearchEmpty"
                    loadingKey="common.loading"
                    noParentKey="issueShow.noParent"
                    inputId="parent-search-input"
                    inputTestId="parent-search-input"
                    resultsTestId="parent-search-results"
                    resultTestId="parent-search-result"
                    emptyTestId="parent-search-empty"
                    noneTestId="parent-none"
                />
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
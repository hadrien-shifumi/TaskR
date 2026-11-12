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
import type { MilestoneCard } from '@/types';

type MilestoneSetParentDialogProps = {
    milestone: MilestoneCard;
    /** Title of the attached parent, when any — the autocomplete displays it. */
    currentTitle?: string | null;
    open: boolean;
    onClose: () => void;
};

/**
 * Live parent autocomplete for milestones: debounced title search scoped
 * to the project server-side, results picked from a list, "no parent" option
 * reachable while the query is empty. The cycle and cross-project rules live
 * in the action — the 422 refusal surfaces as a banner-danger inside.
 */
export function MilestoneSetParentDialog({ milestone, currentTitle, open, onClose }: MilestoneSetParentDialogProps) {
    return (
        <Dialog
            open={open}
            onOpenChange={(o) => {
                if (!o) onClose();
            }}
        >
            {open ? <MilestoneSetParentForm milestone={milestone} currentTitle={currentTitle} onClose={onClose} /> : null}
        </Dialog>
    );
}

function MilestoneSetParentForm({
    milestone,
    currentTitle,
    onClose,
}: {
    milestone: MilestoneCard;
    currentTitle?: string | null;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const [parentId, setParentId] = useState<string>(milestone.parentId ?? 'none');
    const [refused, setRefused] = useState<string | null>(null);

    const handleChange = (id: string) => {
        setParentId(id);
        setRefused(null);
    };

    const setParent = (e: React.FormEvent) => {
        e.preventDefault();
        setRefused(null);
        router.post(
            route('milestones.parent', milestone.id),
            { parent_id: parentId === 'none' ? null : parentId },
            {
                onSuccess: () => onClose(),
                onHttpException: (response) => {
                    setRefused(t('milestones.parentError'));
                    return false;
                },
            },
        );
    };

    const searchMilestones = (q: string): Promise<ParentSearchResult[]> =>
        fetch(route('milestones.search', { project: milestone.projectId, q, limit: 10 }), {
            headers: { Accept: 'application/json' },
        })
            .then((response) => (response.ok ? response.json() : Promise.reject(response.status)))
            .then((payload: ParentSearchResult[]) => payload)
            .catch(() => []);

    return (
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{t('milestones.parentTitle')}</DialogTitle>
                <DialogDescription>{t('milestones.parentHint')}</DialogDescription>
            </DialogHeader>
            <form onSubmit={setParent} className="grid gap-4">
                <ParentAutocomplete
                    value={parentId}
                    onChange={handleChange}
                    currentTitle={currentTitle}
                    search={searchMilestones}
                    excludeIds={[milestone.id]}
                    labelKey="milestones.parentTitle"
                    placeholderKey="milestones.parentSearchPlaceholder"
                    emptyKey="milestones.parentSearchEmpty"
                    loadingKey="common.loading"
                    noParentKey="milestones.noParent"
                    inputId="milestone-parent-search-input"
                    inputTestId="milestone-parent-search-input"
                    resultsTestId="milestone-parent-search-results"
                    resultTestId="milestone-parent-search-result"
                    emptyTestId="milestone-parent-search-empty"
                    noneTestId="milestone-parent-none"
                />
                {refused ? (
                    <Banner variant="danger" role="alert" data-test="milestone-parent-error">
                        <strong>{t('milestones.parentRefused')}</strong> {refused}
                    </Banner>
                ) : null}
                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button type="submit" data-test="milestone-parent-submit">
                        {t('milestones.setParent')}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}
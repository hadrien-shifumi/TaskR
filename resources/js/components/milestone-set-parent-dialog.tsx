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
import type { MilestoneCard, MilestoneOption } from '@/types';

type MilestoneSetParentDialogProps = {
    milestone: MilestoneCard;
    /** Same-project milestones minus the milestone itself. */
    candidates: MilestoneOption[];
    open: boolean;
    onClose: () => void;
};

/**
 * Maquette dlg-set-parent pattern applied to milestones: same-project
 * candidates only, cycles and self-parenting refused by the action —
 * the 422 refusal surfaces as a banner-danger inside the dialog.
 */
export function MilestoneSetParentDialog({ milestone, candidates, open, onClose }: MilestoneSetParentDialogProps) {
    return (
        <Dialog
            open={open}
            onOpenChange={(o) => {
                if (!o) onClose();
            }}
        >
            {open ? (
                <MilestoneSetParentForm milestone={milestone} candidates={candidates} onClose={onClose} />
            ) : null}
        </Dialog>
    );
}

function MilestoneSetParentForm({
    milestone,
    candidates,
    onClose,
}: {
    milestone: MilestoneCard;
    candidates: MilestoneOption[];
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const [parentId, setParentId] = useState<string>(milestone.parentId ?? 'none');
    const [refused, setRefused] = useState<string | null>(null);

    const setParent = (e: React.FormEvent) => {
        e.preventDefault();
        setRefused(null);
        router.post(            route('milestones.parent', milestone.id),
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

    return (
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{t('milestones.parentTitle')}</DialogTitle>
                <DialogDescription>{t('milestones.parentHint')}</DialogDescription>
            </DialogHeader>
            <form onSubmit={setParent} className="grid gap-4">
                <div className="grid gap-2">
                    <Label htmlFor="milestone-parent-select">{t('milestones.parentTitle')}</Label>
                    <Select
                        value={parentId}
                        onValueChange={(value) => {
                            if (value) {
                                setParentId(value);
                                setRefused(null);
                            }
                        }}
                    >
                        <SelectTrigger id="milestone-parent-select" data-test="milestone-parent-select" className="w-full">
                            <SelectValue>
                                {parentId === 'none'
                                    ? t('milestones.noParent')
                                    : (candidates.find((c) => c.id === parentId)?.title ??
                                      t('milestones.noParent'))}
                            </SelectValue>
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">{t('milestones.noParent')}</SelectItem>
                            {candidates.map((candidate) => (
                                <SelectItem key={candidate.id} value={candidate.id}>
                                    {candidate.title}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
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

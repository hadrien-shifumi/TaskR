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
import { useTranslation } from 'react-i18next';
import type { MilestoneCard } from '@/types';

type MilestoneDeleteDialogProps = {
    /** Milestone being deleted, or null when the dialog is closed. */
    milestone: MilestoneCard | null;
    onClose: () => void;
};

/**
 * Destructive confirmation of a milestone deletion (manager-only
 * action): attached issues are detached, sub-milestones climb one
 * level up — nothing is deleted in cascade, but the act is final.
 */
export function MilestoneDeleteDialog({ milestone, onClose }: MilestoneDeleteDialogProps) {
    const { t } = useTranslation();

    const confirmDelete = () => {
        if (!milestone) return;
        router.delete(route('milestones.destroy', milestone.id), { onSuccess: () => onClose() });
    };

    return (
        <Dialog
            open={milestone !== null}
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            {milestone ? (
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('milestones.deleteTitle')}</DialogTitle>
                        <DialogDescription>“{milestone.title}”</DialogDescription>
                    </DialogHeader>
                    <Banner variant="danger">{t('milestones.deleteDescription')}</Banner>
                    <DialogFooter>
                        <Button variant="ghost" onClick={onClose}>
                            {t('common.cancel')}
                        </Button>
                        <Button variant="destructive" data-test="confirm-delete-milestone" onClick={confirmDelete}>
                            {t('milestones.delete')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            ) : null}
        </Dialog>
    );
}

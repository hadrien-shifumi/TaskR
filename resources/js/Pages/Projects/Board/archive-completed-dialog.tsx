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

type ArchiveCompletedDialogProps = {
    project: { id: string; name: string };
    /** Unarchived done/cancelled issue count, from the board columns. */
    completedCount: number;
    onClose: () => void;
};

/**
 * Owner-only confirmation before the bulk archive of a project's
 * done/cancelled issues.
 */
export function ArchiveCompletedDialog({ project, completedCount, onClose }: ArchiveCompletedDialogProps) {
    const { t } = useTranslation();

    const confirm = () => {
        router.post(route('projects.archive-completed', project.id), {}, {
            onSuccess: () => onClose(),
        });
    };

    return (
        <Dialog open onOpenChange={(open) => {
            if (!open) onClose();
        }}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('archiveCompleted.title', { name: project.name })}</DialogTitle>
                    <DialogDescription>
                        {completedCount > 0
                            ? t('archiveCompleted.confirm', { count: completedCount })
                            : t('archiveCompleted.nothingToDo')}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" size="sm" onClick={onClose}>
                        {t('archiveCompleted.cancel')}
                    </Button>
                    <Button size="sm" data-test="confirm-archive-completed" disabled={completedCount === 0} onClick={confirm}>
                        {t('archiveCompleted.confirmButton')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

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
import { Archive } from 'lucide-react';
import { useTranslation } from 'react-i18next';

type ArchiveCompletedDialogProps = {
    /** Project whose board triggered the action. */
    projectId: string;
    /** Whether the confirmation dialog is open. */
    open: boolean;
    /** Done and cancelled issue counts shown in the confirmation. */
    doneCount: number;
    cancelledCount: number;
    onClose: () => void;
};

/**
 * Confirmation only: the server decides what is actually archivable
 * (closed subtrees) and reports the archived count through the flash
 * message.
 */
export function ArchiveCompletedDialog({
    projectId,
    open,
    doneCount,
    cancelledCount,
    onClose,
}: ArchiveCompletedDialogProps) {
    const { t } = useTranslation();

    const confirm = () => {
        router.post(route('projects.issues.archive-completed', projectId));
        onClose();
    };

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? undefined : onClose())}>
            <DialogContent data-test="archive-completed-dialog">
                <DialogHeader>
                    <DialogTitle>{t('projectBoard.archiveCompletedTitle')}</DialogTitle>
                    <DialogDescription>
                        {t('projectBoard.archiveCompletedDescription')}
                    </DialogDescription>
                </DialogHeader>
                <p
                    className="text-sm font-medium tabular-nums"
                    data-test="archive-completed-counts"
                >
                    {t('projectBoard.archiveCompletedCounts', {
                        done: doneCount,
                        cancelled: cancelledCount,
                    })}
                </p>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        {t('projectBoard.archiveCompletedCancel')}
                    </Button>
                    <Button data-test="archive-completed-confirm" onClick={confirm}>
                        <Archive className="size-3.5" aria-hidden />
                        {t('projectBoard.archiveCompletedConfirm')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

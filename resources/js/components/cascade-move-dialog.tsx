import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useTranslation } from 'react-i18next';

type CascadeMoveDialogProps = {
    /** Active sub-tickets not already in the target status. */
    count: number;
    /** Name of the target status. */
    targetStatusName: string;
    /** Receives the user's answer: true = move the whole sub-tree. */
    onConfirm: (cascade: boolean) => void;
    onClose: () => void;
};

/**
 * Confirmation shown before a parent's status change may bring its whole
 * active sub-tree along. The count and the target status name ride the
 * server-provided descendants summary; the server enforces the rule either
 * way, so a refusal (a lone move to Done with an open sub-ticket) surfaces
 * on the calling screen.
 */
export function CascadeMoveDialog({
    count,
    targetStatusName,
    onConfirm,
    onClose,
}: CascadeMoveDialogProps) {
    const { t } = useTranslation();

    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            <DialogContent data-test="cascade-move-dialog">
                <DialogHeader>
                    <DialogTitle>{t('issues.moveCascade.title')}</DialogTitle>
                    <DialogDescription>
                        {count === 1
                            ? t('issues.moveCascade.descriptionOne', { status: targetStatusName })
                            : t('issues.moveCascade.descriptionMany', { count, status: targetStatusName })}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" data-test="cascade-move-decline" onClick={() => onConfirm(false)}>
                        {t('issues.moveCascade.decline')}
                    </Button>
                    <Button data-test="cascade-move-accept" onClick={() => onConfirm(true)}>
                        {t('issues.moveCascade.accept')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Active sub-tickets of the issue not already in the target status, from
 * the server-provided summary — 0 means the move is direct, no dialog.
 */
export function pendingDescendantsCount(
    summary: { total: number; byStatus: Record<string, number> } | undefined,
    statusId: string,
): number {
    if (!summary) return 0;

    return summary.total - (summary.byStatus[statusId] ?? 0);
}

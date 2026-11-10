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
import type { TimerState } from '@/types';

type StartTimerConflictDialogProps = {
    /** The timers already running when the user asked to start another one. */
    runningTimers: TimerState[];
    /** Stop every running timer first, then start the new one. */
    onStopOthers: () => void;
    /** Keep every running timer and start the new one alongside. */
    onParallel: () => void;
    onClose: () => void;
};

/**
 * Choice posed before starting a timer while others already run: stop the
 * running ones — their elapsed time is proposed for standard entry — or
 * run the new timer in parallel. The server never stops anything
 * silently: without this explicit answer, the new timer starts in
 * parallel.
 */
export function StartTimerConflictDialog({
    runningTimers,
    onStopOthers,
    onParallel,
    onClose,
}: StartTimerConflictDialogProps) {
    const { t } = useTranslation();
    const single = runningTimers.length === 1;

    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            <DialogContent data-test="timer-conflict-dialog" className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('timer.conflictTitle')}</DialogTitle>
                    <DialogDescription>
                        {single
                            ? t('timer.conflictHintSingle', { title: runningTimers[0].issueTitle })
                            : t('timer.conflictHintMultiple', { count: runningTimers.length })}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" data-test="timer-conflict-cancel" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button variant="outline" data-test="timer-conflict-parallel" onClick={onParallel}>
                        {t('timer.startParallel')}
                    </Button>
                    <Button data-test="timer-conflict-stop" onClick={onStopOthers}>
                        {single
                            ? t('timer.stopRunningOne')
                            : t('timer.stopRunningMany', { count: runningTimers.length })}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

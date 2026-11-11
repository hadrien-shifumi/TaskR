import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Banner } from '@/components/banner';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { parseDuration } from '@/lib/parse-duration';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

type LogTimeDialogProps = {
    issueId: string;
    open: boolean;
    onClose: () => void;
    /** Pre-filled hours (timer proposal): the elapsed, rounded value. */
    initialHours?: string;
    /**
     * Title of the issue the entry is recorded on. Shown in the dialog so
     * a timer proposal is never confused with the ticket on screen — the
     * stopped timer may belong to another issue.
     */
    issueTitle?: string;
    /** 24 h guard from the server: warn, never block. */
    exceeds24h?: boolean;
};

/**
 * Maquette dlg-log-time (specified here): duration, date and an optional
 * note. The submit keeps the historical @entry-submit hook of the former
 * inline time-entry form. When opened from a stopped timer, the hours
 * arrive pre-filled and the dialog names the issue they belong to.
 */
export function LogTimeDialog({ issueId, open, onClose, initialHours, issueTitle, exceeds24h }: LogTimeDialogProps) {
    return (
        <Dialog
            open={open}
            onOpenChange={(o) => {
                if (!o) onClose();
            }}
        >
            {open ? (
                <LogTimeForm
                    issueId={issueId}
                    onClose={onClose}
                    initialHours={initialHours}
                    issueTitle={issueTitle}
                    exceeds24h={exceeds24h}
                />
            ) : null}
        </Dialog>
    );
}

function LogTimeForm({
    issueId,
    onClose,
    initialHours,
    issueTitle,
    exceeds24h,
}: {
    issueId: string;
    onClose: () => void;
    initialHours?: string;
    issueTitle?: string;
    exceeds24h?: boolean;
}) {
    const { t } = useTranslation();
    const timeEntryForm = useForm({
        hours: initialHours ?? '',
        worked_at: new Date().toISOString().slice(0, 10),
        note: '',
    });

    const submitTimeEntry = (e: React.FormEvent) => {
        e.preventDefault();
        // The input accepts duration strings ("90m", "1:30"…) — normalize to
        // decimal hours before posting; the server only takes numbers.
        const parsed = parseDuration(timeEntryForm.data.hours);
        if (parsed === null || parsed < 0.01) {
            timeEntryForm.setError('hours', t('issueShow.hoursInvalid'));
            return;
        }
        timeEntryForm.setData('hours', String(parsed));
        timeEntryForm.post(route('issues.time-entries.store', issueId), {
            onSuccess: () => {
                onClose();
                timeEntryForm.reset('hours', 'note');
                timeEntryForm.setData('worked_at', new Date().toISOString().slice(0, 10));
            },
        });
    };

    return (
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{issueTitle ? t('timer.confirmTitle') : t('issueShow.logTime')}</DialogTitle>
                <DialogDescription>
                    {issueTitle ? t('timer.confirmHint', { title: issueTitle }) : t('issueShow.logTimeHint')}
                </DialogDescription>
            </DialogHeader>
            {exceeds24h ? <Banner variant="warning">{t('timer.guard24h')}</Banner> : null}
            <form onSubmit={submitTimeEntry} className="grid gap-4">
                <div className="grid grid-cols-2 gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="log-time-hours">{t('issueShow.duration')}</Label>
                        <p id="log-time-hours-hint" className="text-xs text-muted-foreground">
                            {t('issueShow.hoursHint')}
                        </p>
                        <Input
                            id="log-time-hours"
                            data-test="log-time-hours"
                            type="text"
                            inputMode="decimal"
                            placeholder={t('issueShow.hoursPlaceholder')}
                            required
                            value={timeEntryForm.data.hours}
                            onChange={(e) => timeEntryForm.setData('hours', e.target.value)}
                            aria-invalid={timeEntryForm.errors.hours ? true : undefined}
                            aria-describedby="log-time-hours-hint"
                        />
                        {timeEntryForm.errors.hours ? (
                            <p className="text-sm text-destructive">{timeEntryForm.errors.hours}</p>
                        ) : null}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="log-time-date">{t('issueShow.date')}</Label>
                        <Input
                            id="log-time-date"
                            data-test="log-time-date"
                            type="date"
                            value={timeEntryForm.data.worked_at}
                            onChange={(e) => timeEntryForm.setData('worked_at', e.target.value)}
                            aria-invalid={timeEntryForm.errors.worked_at ? true : undefined}
                        />
                        {timeEntryForm.errors.worked_at ? (
                            <p className="text-sm text-destructive">{timeEntryForm.errors.worked_at}</p>
                        ) : null}
                    </div>
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="log-time-note">{t('issueShow.note')}</Label>
                    <Textarea
                        id="log-time-note"
                        data-test="log-time-note"
                        placeholder={t('issueShow.notePlaceholder')}
                        value={timeEntryForm.data.note}
                        onChange={(e) => timeEntryForm.setData('note', e.target.value)}
                        aria-invalid={timeEntryForm.errors.note ? true : undefined}
                    />
                    {timeEntryForm.errors.note ? (
                        <p className="text-sm text-destructive">{timeEntryForm.errors.note}</p>
                    ) : null}
                </div>
                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button type="submit" data-test="entry-submit" disabled={timeEntryForm.processing}>
                        {t('issueShow.logTime')}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}

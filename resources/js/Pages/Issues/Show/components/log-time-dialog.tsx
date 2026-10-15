import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/react';

type LogTimeDialogProps = {
    issueId: string;
    open: boolean;
    onClose: () => void;
};

/**
 * Maquette dlg-log-time (specified here): duration, date and an optional
 * note. The submit keeps the historical @entry-submit hook of the former
 * inline time-entry form.
 */
export function LogTimeDialog({ issueId, open, onClose }: LogTimeDialogProps) {
    return (
        <Dialog
            open={open}
            onOpenChange={(o) => {
                if (!o) onClose();
            }}
        >
            {open ? <LogTimeForm issueId={issueId} onClose={onClose} /> : null}
        </Dialog>
    );
}

function LogTimeForm({ issueId, onClose }: { issueId: string; onClose: () => void }) {
    const timeEntryForm = useForm({
        hours: '',
        worked_at: new Date().toISOString().slice(0, 10),
        note: '',
    });

    const submitTimeEntry = (e: React.FormEvent) => {
        e.preventDefault();
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
                <DialogTitle>Log time</DialogTitle>
                <DialogDescription>
                    Record worked time on this issue. Only the hours are required.
                </DialogDescription>
            </DialogHeader>
            <form onSubmit={submitTimeEntry} className="grid gap-4">
                <div className="grid grid-cols-2 gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="log-time-hours">Hours</Label>
                        <Input
                            id="log-time-hours"
                            data-test="log-time-hours"
                            type="number"
                            step="any"
                            min="0.01"
                            placeholder="Hours"
                            required
                            value={timeEntryForm.data.hours}
                            onChange={(e) => timeEntryForm.setData('hours', e.target.value)}
                        />
                        {timeEntryForm.errors.hours ? (
                            <p className="text-sm text-destructive">{timeEntryForm.errors.hours}</p>
                        ) : null}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="log-time-date">Date</Label>
                        <Input
                            id="log-time-date"
                            data-test="log-time-date"
                            type="date"
                            value={timeEntryForm.data.worked_at}
                            onChange={(e) => timeEntryForm.setData('worked_at', e.target.value)}
                        />
                        {timeEntryForm.errors.worked_at ? (
                            <p className="text-sm text-destructive">{timeEntryForm.errors.worked_at}</p>
                        ) : null}
                    </div>
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="log-time-note">Note</Label>
                    <Textarea
                        id="log-time-note"
                        data-test="log-time-note"
                        placeholder="Note (optional)"
                        value={timeEntryForm.data.note}
                        onChange={(e) => timeEntryForm.setData('note', e.target.value)}
                    />
                    {timeEntryForm.errors.note ? (
                        <p className="text-sm text-destructive">{timeEntryForm.errors.note}</p>
                    ) : null}
                </div>
                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" data-test="entry-submit" disabled={timeEntryForm.processing}>
                        Log time
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}

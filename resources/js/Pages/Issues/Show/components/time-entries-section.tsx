import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { IssueDetail, TimeEntry } from '../types';

export function formatHours(hours: number | null): string {
    if (hours === null) return '—';
    return `${hours}h`;
}

type TimeEntriesSectionProps = {
    issue: IssueDetail;
    timeEntries: TimeEntry[];
};

/** Logged time entries with author-scoped inline editing and the log form. */
export function TimeEntriesSection({ issue, timeEntries }: TimeEntriesSectionProps) {
    const { auth } = usePage<{ auth: { user: { id: number } | null } }>().props;
    const [editingTimeEntry, setEditingTimeEntry] = useState<string | null>(null);

    const timeEntryForm = useForm({
        hours: '',
        worked_at: new Date().toISOString().slice(0, 10),
        note: '',
    });

    const timeEntryEditForm = useForm({
        hours: '',
        worked_at: '',
        note: '',
    });

    const submitTimeEntry = (e: React.FormEvent) => {
        e.preventDefault();
        timeEntryForm.post(route('issues.time-entries.store', issue.id), {
            onSuccess: () => timeEntryForm.reset('hours', 'note'),
        });
    };

    const submitTimeEntryEdit = (e: React.FormEvent, entryId: string) => {
        e.preventDefault();
        timeEntryEditForm.patch(route('issues.time-entries.update', [issue.id, entryId]), {
            onSuccess: () => setEditingTimeEntry(null),
        });
    };

    const removeTimeEntry = (entryId: string) => {
        if (!window.confirm('Delete this time entry?')) return;
        router.delete(route('issues.time-entries.destroy', [issue.id, entryId]));
    };

    return (
        <div>
            <div className="mb-2 flex items-center justify-between">
                <h2 className="text-lg font-semibold">Time</h2>
                <p className="text-sm text-muted-foreground">
                    {formatHours(issue.loggedHours)}
                    {issue.estimatedHours !== null ? ` / ${formatHours(issue.estimatedHours)}` : ''}
                    {issue.remainingHours !== null ? (
                        <span className={issue.remainingHours < 0 ? 'text-destructive' : ''}>
                            {' '}({formatHours(issue.remainingHours)} left)
                        </span>
                    ) : null}
                </p>
            </div>
            <ul className="space-y-3">
                {timeEntries.map((entry) => (
                    <li key={entry.id} className="rounded-md border px-4 py-3">
                        <div className="mb-1 flex items-center gap-2 text-sm">
                            <span className="font-medium">{entry.authorName}</span>
                            <span className="text-muted-foreground">
                                {entry.workedAt ? new Date(entry.workedAt).toLocaleDateString() : ''}
                            </span>
                            <span className="font-medium">{entry.hours}h</span>
                            {auth.user && auth.user.id === entry.authorId ? (
                                <span className="ml-auto flex gap-1">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        data-test="entry-edit"
                                        onClick={() => {
                                            setEditingTimeEntry(entry.id);
                                            timeEntryEditForm.setData({
                                                hours: String(entry.hours),
                                                worked_at: entry.workedAt ? entry.workedAt.slice(0, 10) : '',
                                                note: entry.note ?? '',
                                            });
                                        }}
                                    >
                                        Edit
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        data-test="entry-delete"
                                        onClick={() => removeTimeEntry(entry.id)}
                                    >
                                        Delete
                                    </Button>
                                </span>
                            ) : null}
                        </div>
                        {editingTimeEntry === entry.id ? (
                            <form onSubmit={(e) => submitTimeEntryEdit(e, entry.id)} className="grid gap-2">
                                <div className="grid grid-cols-2 gap-2">
                                    <Input
                                        type="number"
                                        step="any"
                                        min="0.01"
                                        placeholder="Hours"
                                        data-test="entry-edit-hours"
                                        value={timeEntryEditForm.data.hours}
                                        onChange={(e) => timeEntryEditForm.setData('hours', e.target.value)}
                                        required
                                    />
                                    <Input
                                        type="date"
                                        data-test="entry-edit-worked-at"
                                        value={timeEntryEditForm.data.worked_at}
                                        onChange={(e) => timeEntryEditForm.setData('worked_at', e.target.value)}
                                    />
                                </div>
                                <Textarea
                                    placeholder="Note (optional)"
                                    data-test="entry-edit-note"
                                    value={timeEntryEditForm.data.note}
                                    onChange={(e) => timeEntryEditForm.setData('note', e.target.value)}
                                />
                                <div className="flex gap-2">
                                    <Button type="submit" size="sm" data-test="entry-edit-save" disabled={timeEntryEditForm.processing}>
                                        Save
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => setEditingTimeEntry(null)}
                                    >
                                        Cancel
                                    </Button>
                                </div>
                            </form>
                        ) : (
                            <>
                                {entry.note ? (
                                    <p className="whitespace-pre-wrap text-sm text-muted-foreground">
                                        {entry.note}
                                    </p>
                                ) : null}
                            </>
                        )}
                    </li>
                ))}
                {timeEntries.length === 0 ? (
                    <li className="px-4 py-3 text-sm text-muted-foreground">No time logged yet.</li>
                ) : null}
            </ul>
            <form onSubmit={submitTimeEntry} className="mt-4 grid gap-2">
                <div className="grid grid-cols-2 gap-2">
                    <Input
                        type="number"
                        step="any"
                        min="0.01"
                        placeholder="Hours"
                        value={timeEntryForm.data.hours}
                        onChange={(e) => timeEntryForm.setData('hours', e.target.value)}
                        required
                    />
                    <Input
                        type="date"
                        value={timeEntryForm.data.worked_at}
                        onChange={(e) => timeEntryForm.setData('worked_at', e.target.value)}
                    />
                </div>
                <Textarea
                    placeholder="Note (optional)"
                    value={timeEntryForm.data.note}
                    onChange={(e) => timeEntryForm.setData('note', e.target.value)}
                />
                {timeEntryForm.errors.hours ? (
                    <p className="text-sm text-destructive">{timeEntryForm.errors.hours}</p>
                ) : null}
                <Button type="submit" size="sm" data-test="entry-submit" disabled={timeEntryForm.processing} className="justify-self-start">
                    Log time
                </Button>
            </form>
        </div>
    );
}

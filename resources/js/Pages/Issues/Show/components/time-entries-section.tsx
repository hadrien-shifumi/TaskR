import { Avatar } from '@/components/issue-badges';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { router, useForm, usePage } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
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

    const summary = [
        { label: 'Logged', value: formatHours(issue.loggedHours) },
        { label: 'Estimated', value: formatHours(issue.estimatedHours) },
        {
            label: 'Remaining',
            value:
                issue.remainingHours !== null ? (
                    <span className={issue.remainingHours < 0 ? 'text-destructive' : ''}>
                        {formatHours(issue.remainingHours)} left
                    </span>
                ) : (
                    '—'
                ),
        },
    ];

    return (
        <section>
            <div className="mb-2 flex items-center gap-3">
                <h2 className="text-base font-semibold">Time</h2>
                <span className="font-mono text-xs text-muted-foreground tabular-nums">
                    {formatHours(issue.loggedHours)} logged
                </span>
            </div>

            <div className="mb-4 grid grid-cols-3 gap-4 rounded-lg border bg-card px-4 py-3">
                {summary.map((cell) => (
                    <div key={cell.label}>
                        <p className="text-xs text-muted-foreground">{cell.label}</p>
                        <p className="font-mono text-sm font-semibold tabular-nums">{cell.value}</p>
                    </div>
                ))}
            </div>

            <ul className="divide-y">
                {timeEntries.map((entry) => (
                    <li key={entry.id} className="group flex gap-3 py-3.5">
                        <Avatar name={entry.authorName} />
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-baseline gap-x-2">
                                <span className="text-[13px] font-medium">{entry.authorName}</span>
                                <span className="font-mono text-xs text-muted-foreground tabular-nums">
                                    {entry.hours}h ·{' '}
                                    {entry.workedAt ? new Date(entry.workedAt).toLocaleDateString() : ''}
                                </span>
                                {auth.user && auth.user.id === entry.authorId ? (
                                    <span className="ml-auto flex gap-0.5 opacity-0 transition-opacity group-hover:opacity-100 focus-within:opacity-100 max-lg:opacity-100">
                                        <Button
                                            variant="ghost"
                                            size="icon-sm"
                                            aria-label={`Edit time entry by ${entry.authorName}`}
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
                                            <Pencil aria-hidden />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon-sm"
                                            aria-label={`Delete time entry by ${entry.authorName}`}
                                            data-test="entry-delete"
                                            onClick={() => removeTimeEntry(entry.id)}
                                        >
                                            <Trash2 aria-hidden />
                                        </Button>
                                    </span>
                                ) : null}
                            </div>
                            {editingTimeEntry === entry.id ? (
                                <form onSubmit={(e) => submitTimeEntryEdit(e, entry.id)} className="mt-2 grid gap-2">
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
                            ) : entry.note ? (
                                <p className="mt-0.5 text-[13px] whitespace-pre-wrap text-muted-foreground">
                                    {entry.note}
                                </p>
                            ) : null}
                        </div>
                    </li>
                ))}
                {timeEntries.length === 0 ? (
                    <li className="py-3 text-sm text-muted-foreground">No time logged yet.</li>
                ) : null}
            </ul>

            <form onSubmit={submitTimeEntry} className="mt-4 grid gap-2 rounded-lg border bg-card p-4">
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
                    <Plus className="size-3.5" aria-hidden />
                    Log time
                </Button>
            </form>
        </section>
    );
}

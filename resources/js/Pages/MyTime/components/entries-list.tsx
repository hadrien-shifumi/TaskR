import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { router, useForm, usePage } from '@inertiajs/react';
import { Pencil, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { TimeEntryRow } from '@/types';
import { formatDate } from '@/lib/format-date';
import { formatDuration } from '@/lib/format-duration';
import { parseDuration } from '@/lib/parse-duration';

type EntriesListProps = {
    entries: TimeEntryRow[];
    /** Issue row of the weekly grid currently filtered; null = no filter. */
    activeIssueId?: string | null;
    /** Clears the active filter, back to the full entries list. */
    onClearFilter?: () => void;
};

/**
 * The week's raw entries with author-only edit / delete — the existing
 * issue endpoints, so a mutation from "My time" lands back here with the
 * standard confirmation flash. When an issue is filtered through the
 * weekly grid, only its entries are shown, with a clear control.
 */
export function EntriesList({ entries, activeIssueId, onClearFilter }: EntriesListProps) {
    const { auth } = usePage<{ auth: { user: { id: number } | null } }>().props;
    const { t } = useTranslation();
    const [editingId, setEditingId] = useState<string | null>(null);

    const visibleEntries = activeIssueId ? entries.filter((entry) => entry.issueId === activeIssueId) : entries;

    const editForm = useForm({
        hours: '',
        worked_at: '',
        note: '',
    });

    const openEdit = (entry: TimeEntryRow) => {
        setEditingId(entry.id);
        editForm.setData({
            hours: String(entry.hours),
            worked_at: entry.workedAt.slice(0, 10),
            note: entry.note ?? '',
        });
    };

    const submitEdit = (e: React.FormEvent, entry: TimeEntryRow) => {
        e.preventDefault();
        // Same duration-string input as the issue fiche: normalize to hours.
        const parsed = parseDuration(editForm.data.hours);
        if (parsed === null || parsed < 0.01) {
            editForm.setError('hours', t('issueShow.hoursInvalid'));
            return;
        }
        editForm.setData('hours', String(parsed));
        editForm.patch(route('issues.time-entries.update', [entry.issueId, entry.id]), {
            onSuccess: () => setEditingId(null),
        });
    };

    const removeEntry = (entry: TimeEntryRow) => {
        if (!window.confirm(t('timeEntries.deleteConfirm'))) return;
        router.delete(route('issues.time-entries.destroy', [entry.issueId, entry.id]));
    };

    return (
        <section>
            <h2 className="mb-2 text-[15px] font-semibold tracking-tight">{t('myTime.entriesTitle')}</h2>
            {activeIssueId ? (
                <div className="mb-2 flex items-center justify-between gap-2 rounded-xl border bg-muted/50 px-4 py-2" data-test="entries-filtered">
                    <p className="min-w-0 truncate text-sm font-medium" data-test="entries-filtered-title">
                        {t('myTime.entriesFilteredBy', { title: visibleEntries[0]?.issueTitle ?? '' })}
                    </p>
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        onClick={onClearFilter}
                        aria-label={t('myTime.clearFilter')}
                        data-test="entries-filter-clear"
                    >
                        <X aria-hidden />
                    </Button>
                </div>
            ) : null}
            <ul className="divide-y rounded-xl border bg-card" data-test="entries-list">
                {visibleEntries.map((entry) => (
                    <li key={entry.id} className="group flex gap-3 px-4 py-3" data-test="entry-row">
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-baseline gap-x-2">
                                <span className="text-[13px] font-medium">{entry.issueTitle}</span>
                                <span className="font-mono text-xs text-muted-foreground tabular-nums">
                                    {formatDuration(entry.hours)} · {formatDate(entry.workedAt)}
                                </span>
                            </div>
                            {editingId === entry.id ? (
                                <form onSubmit={(e) => submitEdit(e, entry)} className="mt-2 grid gap-2">
                                    <p className="text-xs text-muted-foreground">{t('issueShow.hoursHint')}</p>
                                    <div className="grid grid-cols-2 gap-2">
                                        <Input
                                            type="text"
                                            inputMode="decimal"
                                            placeholder={t('issueShow.hoursPlaceholder')}
                                            data-test="entry-edit-hours"
                                            value={editForm.data.hours}
                                            onChange={(e) => editForm.setData('hours', e.target.value)}
                                            aria-invalid={editForm.errors.hours ? true : undefined}
                                            required
                                        />
                                        <Input
                                            type="date"
                                            data-test="entry-edit-worked-at"
                                            value={editForm.data.worked_at}
                                            onChange={(e) => editForm.setData('worked_at', e.target.value)}
                                        />
                                    </div>
                                    {editForm.errors.hours ? (
                                        <p className="text-sm text-destructive">{editForm.errors.hours}</p>
                                    ) : null}
                                    <Textarea
                                        placeholder={t('myTime.note')}
                                        data-test="entry-edit-note"
                                        value={editForm.data.note}
                                        onChange={(e) => editForm.setData('note', e.target.value)}
                                    />
                                    <div className="flex gap-2">
                                        <Button type="submit" size="sm" data-test="entry-edit-save" disabled={editForm.processing}>
                                            {t('common.save')}
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => setEditingId(null)}
                                        >
                                            {t('common.cancel')}
                                        </Button>
                                    </div>
                                </form>
                            ) : entry.note ? (
                                <p className="mt-0.5 text-[13px] whitespace-pre-wrap text-muted-foreground">
                                    {entry.note}
                                </p>
                            ) : null}
                        </div>
                        {auth.user && auth.user.id === entry.authorId ? (
                            <span className="flex shrink-0 gap-0.5 self-start max-lg:opacity-100 lg:opacity-0 lg:transition-opacity lg:group-hover:opacity-100 lg:focus-within:opacity-100">
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    aria-label={t('timeEntries.editBy', { name: entry.authorName ?? '' })}
                                    data-test="entry-edit"
                                    onClick={() => openEdit(entry)}
                                >
                                    <Pencil aria-hidden />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    aria-label={t('timeEntries.deleteBy', { name: entry.authorName ?? '' })}
                                    data-test="entry-delete"
                                    onClick={() => removeEntry(entry)}
                                >
                                    <Trash2 aria-hidden />
                                </Button>
                            </span>
                        ) : null}
                    </li>
                ))}
                {visibleEntries.length === 0 ? (
                    <li className="px-4 py-6 text-center text-sm text-muted-foreground" data-test="entries-empty">
                        {t('myTime.entriesEmpty')}
                    </li>
                ) : null}
            </ul>
        </section>
    );
}

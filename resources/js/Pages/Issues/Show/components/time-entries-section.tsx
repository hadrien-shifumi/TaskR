import { Avatar } from '@/components/issue-badges';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { router, useForm, usePage } from '@inertiajs/react';
import { Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { formatDuration } from '@/lib/format-duration';
import { parseDuration } from '@/lib/parse-duration';
import type { IssueDetail, TimeEntry } from '../types';
import { formatDate, formatHours } from './format';
import { LogTimeDialog } from './log-time-dialog';
import { SectionHead } from './section-head';

type TimeEntriesSectionProps = {
    issue: IssueDetail;
    timeEntries: TimeEntry[];
};

/**
 * Maquette issue-time: 3-cell summary (Logged / Estimated / Remaining),
 * entries with author-only actions revealed on hover, and the "Log time"
 * dialog instead of an inline form.
 */
export function TimeEntriesSection({ issue, timeEntries }: TimeEntriesSectionProps) {
    const { auth } = usePage<{ auth: { user: { id: number } | null } }>().props;
    const { t } = useTranslation();
    const [editingTimeEntry, setEditingTimeEntry] = useState<string | null>(null);
    const [logging, setLogging] = useState(false);

    const timeEntryEditForm = useForm({
        hours: '',
        worked_at: '',
        note: '',
    });

    const submitTimeEntryEdit = (e: React.FormEvent, entryId: string) => {
        e.preventDefault();
        // Same duration-string input as the log dialog: normalize to hours.
        const parsed = parseDuration(timeEntryEditForm.data.hours);
        if (parsed === null || parsed < 0.01) {
            timeEntryEditForm.setError('hours', t('issueShow.hoursInvalid'));
            return;
        }
        timeEntryEditForm.setData('hours', String(parsed));
        timeEntryEditForm.patch(route('issues.time-entries.update', [issue.id, entryId]), {
            onSuccess: () => setEditingTimeEntry(null),
        });
    };

    const removeTimeEntry = (entryId: string) => {
        if (!window.confirm(t('timeEntries.deleteConfirm'))) return;
        router.delete(route('issues.time-entries.destroy', [issue.id, entryId]));
    };

    const consumption =
        issue.estimatedHours !== null && issue.estimatedHours > 0
            ? issue.loggedHours / issue.estimatedHours
            : null;

    const summary = [
        {
            id: 'logged',
            label: t('timeEntries.summaryLogged'),
            value: formatHours(issue.loggedHours),
            danger: false,
        },
        {
            id: 'estimated',
            label: t('timeEntries.summaryEstimated'),
            value: formatHours(issue.estimatedHours),
            danger: false,
        },
        {
            id: 'remaining',
            label: t('timeEntries.summaryRemaining'),
            value:
                issue.remainingHours === null
                    ? '—'
                    : issue.remainingHours < 0
                      ? t('timeEntries.estimateExceeded')
                      : t('timeEntries.hoursLeft', { hours: formatHours(issue.remainingHours) }),
            danger: issue.remainingHours !== null && issue.remainingHours < 0,
        },
    ];

    return (
        <section>
            <SectionHead
                title={t('timeEntries.title')}
                count={t('timeEntries.loggedCount', { hours: formatHours(issue.loggedHours) })}
                action={
                    <Button variant="outline" size="sm" data-test="log-time" onClick={() => setLogging(true)}>
                        {t('issueShow.logTime')}
                    </Button>
                }
            />

            <div className="mb-1 flex overflow-hidden rounded-lg border bg-card">
                {summary.map((cell, index) => (
                    <div
                        key={cell.label}
                        data-test={`summary-${cell.id}`}
                        className={`flex-1 px-4 py-3 ${index > 0 ? 'border-l border-border' : ''}`}
                    >
                        <p className="font-mono text-[11px] tracking-[0.04em] text-muted-foreground uppercase">
                            {cell.label}
                        </p>
                        <p
                            data-test={`summary-${cell.id}-value`}
                            className={`mt-0.5 font-mono text-[18px] font-semibold tabular-nums ${
                                cell.danger ? 'text-destructive' : ''
                            }`}
                        >
                            {cell.value}
                        </p>
                    </div>
                ))}
            </div>

            {consumption !== null ? (
                <div
                    data-test="time-progress"
                    role="progressbar"
                    aria-label={t('timeEntries.consumptionLabel')}
                    aria-valuemin={0}
                    aria-valuemax={100}
                    aria-valuenow={Math.round(Math.min(100, consumption * 100))}
                    className="mb-1 h-1.5 overflow-hidden rounded-full bg-muted"
                >
                    <div
                        data-test="time-progress-fill"
                        className={`h-full rounded-full ${consumption > 1 ? 'bg-destructive' : 'bg-primary'}`}
                        style={{ width: `${Math.min(100, consumption * 100)}%` }}
                    />
                </div>
            ) : null}

            <ul className="divide-y">
                {timeEntries.map((entry) => (
                    <li key={entry.id} className="flex gap-3 py-3.5">
                        <Avatar name={entry.authorName} />
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-baseline gap-x-2">
                                <span className="text-[13px] font-medium">{entry.authorName}</span>
                                <span className="font-mono text-xs text-muted-foreground tabular-nums">
                                    {formatDuration(entry.hours)} · {entry.workedAt ? formatDate(entry.workedAt) : ''}
                                </span>
                            </div>
                            {editingTimeEntry === entry.id ? (
                                <form onSubmit={(e) => submitTimeEntryEdit(e, entry.id)} className="mt-2 grid gap-2">
                                    <p className="text-xs text-muted-foreground">{t('issueShow.hoursHint')}</p>
                                    <div className="grid grid-cols-2 gap-2">
                                        <Input
                                            type="text"
                                            inputMode="decimal"
                                            placeholder={t('issueShow.hoursPlaceholder')}
                                            data-test="entry-edit-hours"
                                            value={timeEntryEditForm.data.hours}
                                            onChange={(e) => timeEntryEditForm.setData('hours', e.target.value)}
                                            aria-invalid={timeEntryEditForm.errors.hours ? true : undefined}
                                            required
                                        />
                                        <Input
                                            type="date"
                                            data-test="entry-edit-worked-at"
                                            value={timeEntryEditForm.data.worked_at}
                                            onChange={(e) => timeEntryEditForm.setData('worked_at', e.target.value)}
                                        />
                                    </div>
                                    {timeEntryEditForm.errors.hours ? (
                                        <p className="text-xs text-destructive">{timeEntryEditForm.errors.hours}</p>
                                    ) : null}
                                    <Textarea
                                        placeholder={t('issueShow.notePlaceholder')}
                                        data-test="entry-edit-note"
                                        value={timeEntryEditForm.data.note}
                                        onChange={(e) => timeEntryEditForm.setData('note', e.target.value)}
                                    />
                                    <div className="flex gap-2">
                                        <Button
                                            type="submit"
                                            size="sm"
                                            data-test="entry-edit-save"
                                            disabled={timeEntryEditForm.processing}
                                        >
                                            {t('common.save')}
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => setEditingTimeEntry(null)}
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
                            <span className="flex shrink-0 gap-0.5 self-start">
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    aria-label={t('timeEntries.editBy', { name: entry.authorName })}
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
                                    aria-label={t('timeEntries.deleteBy', { name: entry.authorName })}
                                    data-test="entry-delete"
                                    onClick={() => removeTimeEntry(entry.id)}
                                >
                                    <Trash2 aria-hidden />
                                </Button>
                            </span>
                        ) : null}
                    </li>
                ))}
                {timeEntries.length === 0 ? (
                    <li className="py-3 text-sm text-muted-foreground">{t('timeEntries.empty')}</li>
                ) : null}
            </ul>

            <LogTimeDialog issueId={issue.id} open={logging} onClose={() => setLogging(false)} />
        </section>
    );
}

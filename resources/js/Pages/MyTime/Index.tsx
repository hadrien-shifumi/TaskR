import { FlashBanner } from '@/components/flash-banner';
import { Banner } from '@/components/banner';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDuration } from '@/lib/format-duration';
import { addDays, currentMonday } from '@/Pages/MyTime/components/week-dates';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Plus, Save } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { IssueSearchResult, TimesheetGrid, TimeEntryRow, WeekTimesheet } from '@/types';
import { AddLineDialog } from './components/add-line-dialog';
import { EntriesList } from './components/entries-list';
import { TimesheetGridTable, type NewLine } from './components/timesheet-grid';
import { WeekNavigator } from './components/week-navigator';

type PageProps = WeekTimesheet & {
    /** Set when the requested URL week was clamped onto the current one. */
    warning?: string | null;
};

type StorePayload = {
    week: string;
    lines: {
        issueId: string;
        note: string | null;
        days: Record<string, number>;
    }[];
};

/**
 * "My time": the weekly timesheet. Logged hours show read-only on the
 * grid — editing goes through the entries list — and the save posts only
 * the filled cells of the lines being added, so a duplicate cell can
 * never overwrite an existing entry (the server refuses it and names the
 * cell). The week is shareable through the `?week=` URL parameter.
 */
export default function Index() {
    const { weekStart, weekEnd, weekTotal, grid, entries, warning } = usePage<PageProps>().props;
    const { t } = useTranslation();

    const [newLines, setNewLines] = useState<NewLine[]>([]);
    const [addOpen, setAddOpen] = useState(false);
    // Payload index → index in `newLines`, to map cell errors back.
    const [sentLineMap, setSentLineMap] = useState<number[]>([]);

    const form = useForm<StorePayload>({
        week: weekStart,
        lines: [],
    });

    const atCurrent = weekStart === currentMonday();
    const atOldest = weekStart === addDays(currentMonday(), -56);

    const go = (week: string) => {
        setNewLines([]);
        setSentLineMap([]);
        router.get(route('my-time.show'), { week }, { preserveState: true, preserveScroll: true });
    };

    const addLine = (issue: IssueSearchResult) => {
        setNewLines((lines) => [
            ...lines,
            { issueId: issue.id, issueTitle: issue.title, note: '', days: {} },
        ]);
    };

    const setCell = (lineIndex: number, day: string, value: string) => {
        setNewLines((lines) => lines.map((line, index) => (
            index === lineIndex ? { ...line, days: { ...line.days, [day]: value } } : line
        )));
    };

    const setNote = (lineIndex: number, note: string) => {
        setNewLines((lines) => lines.map((line, index) => (
            index === lineIndex ? { ...line, note } : line
        )));
    };

    const removeLine = (lineIndex: number) => {
        setNewLines((lines) => lines.filter((_, index) => index !== lineIndex));
    };

    const filledLines = (): { index: number; line: StorePayload['lines'][number] }[] => {
        const payload: { index: number; line: StorePayload['lines'][number] }[] = [];
        newLines.forEach((line, index) => {
            const days: Record<string, number> = {};
            Object.entries(line.days).forEach(([day, value]) => {
                const hours = parseFloat(value);
                if (Number.isFinite(hours) && hours > 0) {
                    days[day] = hours;
                }
            });
            if (Object.keys(days).length > 0) {
                payload.push({
                    index,
                    line: { issueId: line.issueId, note: line.note.trim() !== '' ? line.note.trim() : null, days },
                });
            }
        });
        return payload;
    };

    const hasSomethingToSave = filledLines().length > 0;

    const save = () => {
        const payload = filledLines();
        if (payload.length === 0) return;

        setSentLineMap(payload.map((entry) => entry.index));

        // transform, not setData: the payload is computed here and rides
        // the post as-is — no stale-state race, errors keep their payload
        // indices (`lines.N.days.DATE`).
        form.transform(() => ({
            week: weekStart,
            lines: payload.map((entry) => entry.line),
        }));
        form.post(route('time-entries.week.store'), {
            onSuccess: () => {
                setNewLines([]);
                setSentLineMap([]);
            },
        });
    };

    const existingIds: string[] = [...grid.map((row: TimesheetGrid) => row.issueId), ...newLines.map((line) => line.issueId)];

    return (
        <AppLayout
            title={t('myTime.title')}
            subtitle={t('myTime.subtitle')}
            actions={
                <WeekNavigator weekStart={weekStart} weekEnd={weekEnd} atCurrent={atCurrent} atOldest={atOldest} onGo={go} />
            }
        >
            <Head title={t('myTime.title')} />

            {warning ? (
                <Banner variant="warning" data-test="clamp-warning">
                    {warning}
                </Banner>
            ) : null}

            <FlashBanner />

            <section className="grid gap-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="text-sm text-muted-foreground">{t('myTime.gridHint')}</p>
                    <div className="flex items-center gap-2">
                        <Button variant="outline" size="sm" data-test="add-line" onClick={() => setAddOpen(true)}>
                            <Plus aria-hidden />
                            {t('myTime.addLine')}
                        </Button>
                        <Button
                            size="sm"
                            data-test="save-week"
                            onClick={save}
                            disabled={form.processing || !hasSomethingToSave}
                            title={hasSomethingToSave ? undefined : t('myTime.nothingToSave')}
                        >
                            <Save aria-hidden />
                            {t('myTime.saveWeek')}
                        </Button>
                    </div>
                </div>

                <TimesheetGridTable
                    weekStart={weekStart}
                    existing={grid}
                    lines={newLines}
                    errors={form.errors}
                    sentLineMap={sentLineMap}
                    onCellChange={setCell}
                    onNoteChange={setNote}
                    onRemoveLine={removeLine}
                />

                <p className="text-right text-sm text-muted-foreground" data-test="week-total">
                    {t('myTime.weekTotal')} : <span className="font-mono font-semibold text-foreground tabular-nums">{formatDuration(weekTotal)}</span>
                </p>
            </section>

            <div className="mt-8">
                <EntriesList entries={entries} />
            </div>

            <AddLineDialog
                open={addOpen}
                onClose={() => setAddOpen(false)}
                onPick={addLine}
                excludeIds={existingIds}
            />
        </AppLayout>
    );
}

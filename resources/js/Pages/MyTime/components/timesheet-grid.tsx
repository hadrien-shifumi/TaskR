import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { X } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { formatShortDate } from '@/lib/format-date';
import { formatDuration } from '@/lib/format-duration';
import type { TimesheetGrid } from '@/types';
import { weekDays } from './week-dates';

/** A line being added: raw input values, converted to hours at save time. */
export type NewLine = {
    issueId: string;
    issueTitle: string;
    note: string;
    days: Record<string, string>;
};

type TimesheetGridTableProps = {
    /** Monday of the displayed week, `YYYY-MM-DD`. */
    weekStart: string;
    /** Rows already logged (read-only cells — editing goes to the list). */
    existing: TimesheetGrid[];
    /** Lines being added, editable cell by cell. */
    lines: NewLine[];
    /** Validation errors keyed by payload cell (`lines.N.days.DATE`). */
    errors: Record<string, string>;
    /** Payload index → index in `lines`, for error mapping. */
    sentLineMap: number[];
    /** Issue row currently filtered in the entries list; null = no filter. */
    activeIssueId?: string | null;
    /** Toggle the filter of a grid row (click again to clear it). */
    onToggleIssue?: (issueId: string) => void;
    onCellChange: (lineIndex: number, day: string, value: string) => void;
    onNoteChange: (lineIndex: number, note: string) => void;
    onRemoveLine: (lineIndex: number) => void;
};

/** Renders an hours cell value as a duration: `2h30`, or the em dash. */
function cellHours(value: number | undefined): string {
    if (value === undefined || value === 0) return '—';
    return formatDuration(value);
}

/**
 * The weekly grid: one row per ticket, Monday to Sunday plus the row and
 * week totals. Logged rows are read-only — a duplicate cell would be
 * refused anyway — so new hours are typed on the lines being added, and
 * the submission carries only the filled new cells. A logged row can also
 * carry the filter of the entries list: clicking its issue toggles the
 * active filter (re-click clears it).
 */
export function TimesheetGridTable({
    weekStart,
    existing,
    lines,
    errors,
    sentLineMap,
    activeIssueId,
    onToggleIssue,
    onCellChange,
    onNoteChange,
    onRemoveLine,
}: TimesheetGridTableProps) {
    const { t } = useTranslation();
    const days = weekDays(weekStart);
    const today = new Date().toISOString().slice(0, 10);

    const lineError = (lineIndex: number, day: string): string | undefined => {
        const payloadIndex = sentLineMap.indexOf(lineIndex);
        return payloadIndex >= 0 ? errors[`lines.${payloadIndex}.days.${day}`] : undefined;
    };

    return (
        <div className="overflow-x-auto rounded-xl border bg-card" data-test="timesheet-grid">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead className="min-w-[220px]">{t('myTime.gridTitle')}</TableHead>
                        {days.map((day) => (
                            <TableHead
                                key={day}
                                className={`w-20 text-center tabular-nums ${day === today ? 'text-primary' : ''}`}
                            >
                                <span className="block">{formatShortDate(day)}</span>
                            </TableHead>
                        ))}
                        <TableHead className="w-20 text-right">{t('myTime.lineTotal')}</TableHead>
                        {lines.length > 0 ? <TableHead className="w-10" /> : null}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {existing.map((row) => (
                        <TableRow
                            key={row.issueId}
                            data-test="grid-row-existing"
                            className={activeIssueId === row.issueId ? 'bg-muted/60' : undefined}
                        >
                            <TableCell className="max-w-[220px] truncate font-medium">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="h-7 max-w-full justify-start gap-1 px-1 text-[13px] font-medium text-foreground hover:bg-transparent"
                                    onClick={() => onToggleIssue?.(row.issueId)}
                                    aria-pressed={activeIssueId === row.issueId}
                                    data-test={`grid-row-toggle-${row.issueId}`}
                                >
                                    <span className="block truncate" title={row.issueTitle}>
                                        {row.issueTitle}
                                    </span>
                                </Button>
                            </TableCell>
                            {days.map((day) => (
                                <TableCell
                                    key={day}
                                    className="text-center font-mono text-[13px] tabular-nums text-muted-foreground"
                                >
                                    {cellHours(row.days[day])}
                                </TableCell>
                            ))}
                            <TableCell className="text-right font-mono text-[13px] font-semibold tabular-nums">
                                {formatDuration(row.weekTotal)}
                            </TableCell>
                        </TableRow>
                    ))}
                    {lines.map((line, lineIndex) => {
                        const total = days.reduce((sum, day) => sum + (parseFloat(line.days[day]) || 0), 0);
                        return (
                            <TableRow key={line.issueId} data-test="grid-row-new">
                                <TableCell className="max-w-[220px]">
                                    <span className="block truncate font-medium" title={line.issueTitle}>
                                        {line.issueTitle}
                                    </span>
                                    <Textarea
                                        value={line.note}
                                        onChange={(e) => onNoteChange(lineIndex, e.target.value)}
                                        placeholder={t('myTime.notePlaceholder')}
                                        rows={2}
                                        className="mt-1.5 min-h-0 text-[13px]"
                                        data-test="line-note"
                                    />
                                </TableCell>
                                {days.map((day) => {
                                    const error = lineError(lineIndex, day);
                                    return (
                                        <TableCell key={day} className="text-center">
                                            <Input
                                                type="number"
                                                step="0.25"
                                                min="0"
                                                inputMode="decimal"
                                                value={line.days[day] ?? ''}
                                                onChange={(e) => onCellChange(lineIndex, day, e.target.value)}
                                                aria-invalid={error ? true : undefined}
                                                aria-label={`${line.issueTitle} — ${day}`}
                                                data-test={`cell-${day}`}
                                                className="h-8 w-16 px-1.5 text-center font-mono text-[13px] tabular-nums"
                                            />
                                            {error ? (
                                                <p className="mt-1 max-w-24 text-[10px] leading-tight text-destructive" data-test="cell-error">
                                                    {error}
                                                </p>
                                            ) : null}
                                        </TableCell>
                                    );
                                })}
                                <TableCell className="text-right font-mono text-[13px] font-semibold tabular-nums">
                                    {total > 0 ? formatDuration(total) : '—'}
                                </TableCell>
                                <TableCell>
                                    <Button
                                        variant="ghost"
                                        size="icon-sm"
                                        aria-label={t('myTime.removeLine')}
                                        title={t('myTime.removeLine')}
                                        onClick={() => onRemoveLine(lineIndex)}
                                    >
                                        <X aria-hidden />
                                    </Button>
                                </TableCell>
                            </TableRow>
                        );
                    })}
                    {existing.length === 0 && lines.length === 0 ? (
                        <TableRow>
                            <TableCell colSpan={9} className="py-8 text-center text-sm text-muted-foreground">
                                {t('myTime.entriesEmpty')}
                            </TableCell>
                        </TableRow>
                    ) : null}
                </TableBody>
            </Table>
        </div>
    );
}

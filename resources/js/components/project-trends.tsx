import { Card, CardContent } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { formatDate, formatShortDate } from '@/lib/format-date';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { CartesianGrid, Legend, Line, LineChart, Tooltip, XAxis, YAxis } from 'recharts';

/** One day of the trend series, zero-filled by the backend. */
export interface TrendPoint {
    date: string;
    opened: number;
    closed: number;
    moved: number;
}

/** Backend payload of the trend charts (Projects/Health). */
export interface TrendsData {
    period: '30' | '90' | 'all';
    points: TrendPoint[];
    /** Day of the first journalled status change; closures and moves only exist from then on. */
    journalSince: string | null;
}

type Period = TrendsData['period'];

const PERIODS: Period[] = ['30', '90', 'all'];

const PERIOD_LABELS: Record<Period, string> = {
    '30': 'projectHealth.trendsPeriod30',
    '90': 'projectHealth.trendsPeriod90',
    all: 'projectHealth.trendsPeriodAll',
};

/** Series colors come from the design tokens (light and dark aware). */
const SERIES = [
    { key: 'opened', label: 'projectHealth.trendsOpened', color: 'var(--info)' },
    { key: 'closed', label: 'projectHealth.trendsClosed', color: 'var(--success)' },
    { key: 'moved', label: 'projectHealth.trendsMoved', color: 'var(--chart-3)' },
] as const;

type ProjectTrendsProps = {
    projectId: string;
    trends: TrendsData;
};

/**
 * Daily trend chart of a project (opened / closed / moved issues), with a
 * period selector. The period lives in the page URL: switching reloads the
 * trends prop only (server-computed series, no client fetching).
 */
export function ProjectTrends({ projectId, trends }: ProjectTrendsProps) {
    const { t } = useTranslation();

    const changePeriod = (period: Period | null) => {
        if (!period) {
            return;
        }
        router.get(route('projects.health', projectId), { period }, {
            preserveState: true,
            preserveScroll: true,
            only: ['trends'],
        });
    };

    // The journal only dates back to its introduction: when the selected
    // window starts before it, closures and moves read as zero until then.
    const windowStart = trends.points[0] ? new Date(trends.points[0].date) : null;
    const journalFrom = trends.journalSince !== null ? new Date(trends.journalSince) : null;
    const journalCutsTheWindow =
        windowStart !== null && journalFrom !== null && windowStart.getTime() < journalFrom.getTime();

    return (
        <Card className="py-5" data-test="trends">
            <CardContent className="grid gap-3.5 px-5">
                <div className="flex items-center justify-between gap-3">
                    <h2 className="text-base font-semibold">{t('projectHealth.trends')}</h2>
                    <Select value={trends.period} onValueChange={changePeriod}>
                        <SelectTrigger
                            size="sm"
                            className="w-44"
                            data-test="trends-period"
                            aria-label={t('projectHealth.trendsPeriod')}
                        >
                            <SelectValue>
                                {t(PERIOD_LABELS[trends.period])}
                            </SelectValue>
                        </SelectTrigger>
                        <SelectContent>
                            {PERIODS.map((period) => (
                                <SelectItem key={period} value={period}>
                                    {t(PERIOD_LABELS[period])}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                <div className="h-64" data-test="trends-chart">
                    <LineChart
                        data={trends.points}
                        responsive
                        width="100%"
                        height="100%"
                        margin={{ top: 4, right: 8, bottom: 0, left: -16 }}
                    >
                        <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
                        <XAxis
                            dataKey="date"
                            tickFormatter={(date: string) => formatShortDate(date)}
                            tickLine={false}
                            axisLine={false}
                            tick={{ fill: 'var(--muted-foreground)', fontSize: 11 }}
                            minTickGap={24}
                        />
                        <YAxis
                            allowDecimals={false}
                            tickLine={false}
                            axisLine={false}
                            width={32}
                            tick={{ fill: 'var(--muted-foreground)', fontSize: 11 }}
                        />
                        <Tooltip
                            contentStyle={{
                                background: 'var(--card)',
                                border: '1px solid var(--border)',
                                borderRadius: 8,
                                fontSize: 12,
                            }}
                            labelFormatter={(label) => formatDate(typeof label === 'string' ? label : null)}
                        />
                        <Legend wrapperStyle={{ fontSize: 12 }} />
                        {SERIES.map((series) => (
                            <Line
                                key={series.key}
                                type="monotone"
                                dataKey={series.key}
                                name={t(series.label)}
                                stroke={series.color}
                                strokeWidth={2}
                                dot={false}
                                activeDot={{ r: 3 }}
                            />
                        ))}
                    </LineChart>
                </div>

                {journalCutsTheWindow ? (
                    <p className="text-xs text-muted-foreground">
                        {t('projectHealth.trendsJournalSince', { date: formatDate(trends.journalSince) })}
                    </p>
                ) : null}
            </CardContent>
        </Card>
    );
}

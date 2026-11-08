import { ProjectMenu } from '@/components/project-menu';
import { ProjectTrends, type TrendsData } from '@/components/project-trends';
import { ProjectViewSwitcher } from '@/components/project-view-switcher';
import { Avatar, HealthBadge, PriorityBadge, StatusBadge } from '@/components/issue-badges';
import { Progress, type ProgressColor } from '@/components/progress';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { ArrowLeft, Download } from 'lucide-react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { HealthData } from '@/types';
import { formatDate } from '@/lib/format-date';
import { downloadExport } from '@/lib/download-export';

interface LateIssue {
    id: string;
    title: string;
    priority: string;
    dueDate: string | null;
    statusName: string;
    statusCategory: string;
}

interface PersonWorkload {
    userId: number;
    userName: string;
    openIssues: number;
    totalIssues: number;
    overdueIssues: number;
    estimatedHours: number;
    loggedHours: number;
}

type PageProps = {
    project: {
        id: string;
        name: string;
        description: string | null;
        targetDate: string | null;
    };
    health: HealthData | null;
    canSeeGlobalKpis: boolean;
    trends: TrendsData | null;
    myWorkload: PersonWorkload | null;
    teamWorkload: PersonWorkload[] | null;
    lateIssues: LateIssue[];
};

/** Hours render with at most one decimal: 8 → "8", 142.5 → "142.5". */
function formatHours(hours: number): string {
    return hours.toFixed(1).replace(/\.0$/, '');
}

/** First and last day of the current month, `Y-m-d` — the export window
 *  default, mirroring the server's own fallback: the export is always
 *  bounded, and a cleared bound falls back to the matching month edge. */
function currentMonthBounds(): { after: string; before: string } {
    const now = new Date();
    const after = new Date(now.getFullYear(), now.getMonth(), 1);
    const before = new Date(now.getFullYear(), now.getMonth() + 1, 0);
    const format = (date: Date) =>
        `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;

    return { after: format(after), before: format(before) };
}

export default function Health() {
    const { project, health, canSeeGlobalKpis, trends, myWorkload, teamWorkload, lateIssues } = usePage<PageProps>().props;
    const { t } = useTranslation();

    const targetForm = useForm({
        name: project.name,
        description: project.description ?? '',
        target_date: project.targetDate ?? '',
    });

    // Time entries export: format plus a bounded period, defaulting to the
    // current month. The export is open to every reader of the page — the
    // server enforces each account's perimeter.
    const [exporting, setExporting] = useState(false);
    const [exportError, setExportError] = useState<string | null>(null);
    const [timeExport, setTimeExport] = useState(() => ({ format: 'csv', ...currentMonthBounds() }));

    const exportTimeEntries = async (event: React.FormEvent) => {
        event.preventDefault();
        setExportError(null);

        const result = await downloadExport(
            route('time-entries.export'),
            {
                project: project.id,
                worked_after: timeExport.after,
                worked_before: timeExport.before,
                format: timeExport.format,
            },
            t('projectHealth.export.failed'),
        );

        if (result.ok) {
            setExporting(false);
        } else {
            setExportError(result.error);
        }
    };

    const saveTargetDate = (event: React.FormEvent) => {
        event.preventDefault();
        targetForm.patch(route('projects.update', project.id), {
            onSuccess: () => router.get(route('projects.health', project.id)),
        });
    };

    // The maquette shows whole percentages (71 %, 94 %): round at display time.
    const completion = health?.completionPercentage !== null && health?.completionPercentage !== undefined
        ? Math.round(health.completionPercentage)
        : null;
    const expected = health?.expectedPercentage !== null && health?.expectedPercentage !== undefined
        ? Math.round(health.expectedPercentage)
        : null;
    const weighted = health?.weightedCompletionPercentage !== null && health?.weightedCompletionPercentage !== undefined
        ? Math.round(health.weightedCompletionPercentage)
        : null;
    const leaf = health?.leafCompletionPercentage !== null && health?.leafCompletionPercentage !== undefined
        ? Math.round(health.leafCompletionPercentage)
        : null;
    const paceGap = completion !== null && expected !== null ? expected - completion : null;
    const targetPassed =
        project.targetDate !== null && project.targetDate < new Date().toISOString().slice(0, 10);

    const meters: { key: string; label: string; value: number; color: ProgressColor }[] = health
        ? [
              { key: 'done', label: t('statusCategory.done'), value: health.issuesByCategory.done, color: 'success' },
              { key: 'in_progress', label: t('statusCategory.in_progress'), value: health.issuesByCategory.in_progress, color: 'info' },
              { key: 'backlog', label: t('statusCategory.backlog'), value: health.issuesByCategory.backlog, color: 'muted' },
              {
                  key: 'cancelled',
                  label: t('statusCategory.cancelled'),
                  value: health.issuesByCategory.cancelled,
                  color: 'muted-soft',
              },
          ]
        : [];

    const statCards = health
        ? [
              {
                  key: 'stat-completed',
                  label: t('projectHealth.statCompleted'),
                  value: completion !== null ? String(completion) : '—',
                  suffix: completion !== null ? ' %' : '',
                  hint: t('projectHealth.statCompletedHint', {
                      done: health.issuesByCategory.done,
                      cancelled: health.issuesByCategory.cancelled,
                      total: health.totalIssues,
                  }),
              },
              {
                  key: 'stat-overdue',
                  label: t('projectHealth.statOverdue'),
                  value: String(health.overdueIssues),
                  hint: t('projectHealth.statOverdueHint'),
                  danger: health.overdueIssues > 0,
              },
              {
                  key: 'stat-hours',
                  label: t('projectHealth.statHours'),
                  value: formatHours(health.loggedHours),
                  suffix: ` / ${formatHours(health.estimatedHours)} h`,
                  hint:
                      health.consumptionRatio !== null
                          ? t('projectHealth.consumption', { percent: Math.round(health.consumptionRatio) })
                          : t('projectHealth.noEstimates'),
              },
          ]
        : [];

    return (
        <AppLayout
            leading={
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label={t('projectBoard.backToProjects')}
                    onClick={() => router.get(route('projects.index'))}
                >
                    <ArrowLeft aria-hidden />
                </Button>
            }
            title={
                <>
                    <span>{project.name}</span>
                    {health?.status ? <HealthBadge status={health.status} /> : null}
                </>
            }
            actions={
                <>
                    {project.targetDate !== null ? (
                        <span
                            data-test="pagebar-meta"
                            className="hidden font-mono text-xs text-muted-foreground tabular-nums sm:inline"
                        >
                            {t('home.target', { date: formatDate(project.targetDate) })}
                            {targetPassed ? t('projectBoard.overdueSuffix') : ''}
                        </span>
                    ) : null}
                    <Button
                        variant="outline"
                        size="sm"
                        data-test="time-export"
                        onClick={() => setExporting(true)}
                    >
                        <Download aria-hidden />
                        {t('projectHealth.export.button')}
                    </Button>
                    <ProjectViewSwitcher projectId={project.id} active="health" />
                    <ProjectMenu project={project} issuesCount={health?.totalIssues ?? 0} />
                </>
            }
        >
            <Head title={t('projectHealth.headTitle', { name: project.name })} />

            <p className="mb-5 max-w-[62ch] text-[15px] text-muted-foreground">
                {project.description ? `${project.description} ` : null}
                {canSeeGlobalKpis ? t('projectHealth.intro') : t('projectHealth.memberIntro')}
            </p>

            {/* ── Member view: only "My workload" ── */}
            {!canSeeGlobalKpis && myWorkload ? (
                <div className="grid gap-4">
                    <Card className="gap-2 py-5" data-test="my-workload">
                        <CardContent className="grid gap-3.5 px-5">
                            <div className="flex items-center gap-2.5">
                                <Avatar name={myWorkload.userName} />
                                <div>
                                    <h2 className="text-base font-semibold">{t('projectHealth.myWorkload')}</h2>
                                    <p className="text-xs text-muted-foreground">{myWorkload.userName}</p>
                                </div>
                            </div>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div>
                                    <p className="text-xs text-muted-foreground">{t('projectHealth.openIssues')}</p>
                                    <p className="font-mono text-2xl font-semibold tabular-nums">
                                        {myWorkload.openIssues}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-xs text-muted-foreground">{t('projectHealth.hoursLogged')}</p>
                                    <p className="font-mono text-2xl font-semibold tabular-nums">
                                        {formatHours(myWorkload.loggedHours)}
                                        <span className="text-sm text-muted-foreground">
                                            {' '}
                                            / {formatHours(myWorkload.estimatedHours)} h
                                        </span>
                                    </p>
                                </div>
                                <div>
                                    <p className="text-xs text-muted-foreground">{t('projectHealth.overdue')}</p>
                                    <p
                                        className={`font-mono text-2xl font-semibold tabular-nums ${
                                            myWorkload.overdueIssues > 0 ? 'text-destructive' : ''
                                        }`}
                                    >
                                        {myWorkload.overdueIssues}
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            ) : null}

            {/* ── Owner/Manager view: global KPIs + team workload ── */}
            {canSeeGlobalKpis && health ? (
                <>
                    <div className="mb-6 grid gap-4 sm:grid-cols-3">
                        {statCards.map((card) => (
                            <Card key={card.key} data-test={card.key} className="gap-2 py-5">
                                <CardContent className="grid gap-1.5 px-5">
                                    <p className="text-xs text-muted-foreground">{card.label}</p>
                                    <p
                                        className={`font-mono text-[28px] leading-none font-semibold tracking-tight tabular-nums ${
                                            card.danger ? 'text-destructive' : ''
                                        }`}
                                    >
                                        {card.value}
                                        {card.suffix ? (
                                            <span className="text-[15px] text-muted-foreground">{card.suffix}</span>
                                        ) : null}
                                    </p>
                                    <p className="text-xs text-muted-foreground">{card.hint}</p>
                                </CardContent>
                            </Card>
                        ))}
                    </div>

                    <div className="grid items-start gap-7 lg:grid-cols-[1fr_280px]">
                        <div className="grid gap-4">
                            <Card className="py-5">
                                <CardContent className="grid gap-3.5 px-5">
                                    <div className="flex items-center justify-between">
                                        <h2 className="text-base font-semibold">{t('projectHealth.distribution')}</h2>
                                        <span className="font-mono text-xs text-muted-foreground tabular-nums">
                                            {t('projectHealth.issuesCount', { count: health.totalIssues })}
                                        </span>
                                    </div>
                                    {meters.map((meter) => (
                                        <div key={meter.key} data-test={`dist-${meter.key}`} className="flex items-center gap-2.5">
                                            <span className="w-23 flex-none">
                                                <StatusBadge name={meter.label} category={meter.key} />
                                            </span>
                                            <Progress
                                                value={
                                                    health.totalIssues > 0
                                                        ? Math.round((meter.value / health.totalIssues) * 100)
                                                        : 0
                                                }
                                                color={meter.color}
                                                className="flex-1"
                                            />
                                            <span className="w-9 text-right font-mono text-xs tabular-nums">
                                                {meter.value}
                                            </span>
                                        </div>
                                    ))}
                                </CardContent>
                            </Card>

                            {/* Daily trend chart (opened / closed / moved) */}
                            {trends ? <ProjectTrends projectId={project.id} trends={trends} /> : null}

                            {/* Team workload table */}
                            {teamWorkload && teamWorkload.length > 0 ? (
                                <Card className="py-5" data-test="team-workload">
                                    <CardContent className="grid gap-3.5 px-5">
                                        <h2 className="text-base font-semibold">{t('projectHealth.teamWorkload')}</h2>
                                        <Table>
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead>{t('projectHealth.member')}</TableHead>
                                                    <TableHead className="text-right">{t('projectHealth.openIssues')}</TableHead>
                                                    <TableHead className="text-right">{t('projectHealth.hours')}</TableHead>
                                                    <TableHead className="text-right">{t('projectHealth.overdue')}</TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {teamWorkload.map((person) => (
                                                    <TableRow key={person.userId}>
                                                        <TableCell>
                                                            <Link
                                                                href={route('projects.memberActivity', { project: project.id, member: String(person.userId) })}
                                                                className="flex items-center gap-2.5 rounded-md hover:bg-muted/60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                                                data-test="team-member-activity-link"
                                                            >
                                                                <Avatar name={person.userName} />
                                                                <span className="font-medium">{person.userName}</span>
                                                            </Link>
                                                        </TableCell>
                                                        <TableCell className="text-right font-mono tabular-nums">
                                                            {person.openIssues}
                                                        </TableCell>
                                                        <TableCell className="text-right font-mono tabular-nums">
                                                            {formatHours(person.loggedHours)} / {formatHours(person.estimatedHours)} h
                                                        </TableCell>
                                                        <TableCell
                                                            className={`text-right font-mono tabular-nums ${
                                                                person.overdueIssues > 0 ? 'text-destructive' : ''
                                                            }`}
                                                        >
                                                            {person.overdueIssues}
                                                        </TableCell>
                                                    </TableRow>
                                                ))}
                                            </TableBody>
                                        </Table>
                                    </CardContent>
                                </Card>
                            ) : null}

                            <div className="overflow-hidden rounded-xl border bg-card">
                                <div className="flex items-center gap-2.5 border-b px-5 py-3.5">
                                    <h3 className="text-sm font-semibold">{t('projectHealth.lateIssues')}</h3>
                                    <div className="flex-1" />
                                    <Link
                                        href={route('issues.index', { project: project.id })}
                                        className="text-sm text-muted-foreground hover:text-foreground"
                                    >
                                        {t('projectHealth.allIssues')}
                                    </Link>
                                </div>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>{t('columns.title')}</TableHead>
                                            <TableHead>{t('columns.status')}</TableHead>
                                            <TableHead>{t('columns.priority')}</TableHead>
                                            <TableHead>{t('columns.dueDate')}</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {lateIssues.map((issue) => (
                                            <TableRow key={issue.id}>
                                                <TableCell>
                                                    <Link
                                                        href={route('issues.show', issue.id)}
                                                        className="font-medium hover:text-primary"
                                                    >
                                                        {issue.title}
                                                    </Link>
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge name={issue.statusName} category={issue.statusCategory} />
                                                </TableCell>
                                                <TableCell>
                                                    <PriorityBadge priority={issue.priority} />
                                                </TableCell>
                                                <TableCell className="font-mono text-xs font-medium text-destructive tabular-nums">
                                                    {formatDate(issue.dueDate)}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                        {lateIssues.length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={4} className="py-6 text-center text-muted-foreground">
                                                    {t('projectHealth.noLateIssues')}
                                                </TableCell>
                                            </TableRow>
                                        ) : null}
                                    </TableBody>
                                </Table>
                            </div>
                        </div>

                        <Card className="py-5">
                            <CardContent className="grid gap-3.5 px-5">
                                <div className="flex items-center justify-between gap-2">
                                    <h2 className="text-base font-semibold">{t('projectHealth.projection')}</h2>
                                    <span data-test="projection-status">
                                        <HealthBadge status={health.status} />
                                    </span>
                                </div>
                                <div data-test="projection-done">
                                    <div className="mb-1.5 flex items-center justify-between text-sm">
                                        <span className="text-muted-foreground">{t('projectHealth.doneToDate')}</span>
                                        <span className="font-mono font-semibold tabular-nums">{completion ?? 0}%</span>
                                    </div>
                                    <Progress value={completion ?? 0} color="success" />
                                    <p className="mt-1.5 flex flex-wrap gap-x-3 text-xs text-muted-foreground">
                                        <span data-test="stat-weighted" title={t('projectHealth.statWeightedHint')}>
                                            {t('projectHealth.statWeighted')}{' '}
                                            <span className="font-mono text-foreground tabular-nums">
                                                {weighted !== null ? `${weighted} %` : '—'}
                                            </span>
                                        </span>
                                        <span data-test="stat-leaf" title={t('projectHealth.statLeafHint')}>
                                            {t('projectHealth.statLeaf')}{' '}
                                            <span className="font-mono text-foreground tabular-nums">
                                                {leaf !== null ? `${leaf} %` : '—'}
                                            </span>
                                        </span>
                                    </p>
                                </div>
                                <div data-test="projection-expected">
                                    <div className="mb-1.5 flex items-center justify-between text-sm">
                                        <span className="text-muted-foreground">{t('projectHealth.expectedToDate')}</span>
                                        <span className="font-mono font-semibold tabular-nums">
                                            {expected !== null ? `${expected}%` : '—'}
                                        </span>
                                    </div>
                                    <Progress
                                        value={expected ?? 0}
                                        color={health.status === 'late' ? 'destructive' : 'muted'}
                                    />
                                </div>
                                <hr className="border-border" />
                                <p className="text-[12.5px] text-muted-foreground">
                                    {health.targetDate === null ? (
                                        t('projectHealth.noTargetProjection')
                                    ) : health.status === 'late' && paceGap !== null ? (
                                        <>
                                            {t('projectHealth.lateProjection', {
                                                date: formatDate(health.targetDate),
                                                gap: paceGap,
                                            })}{' '}
                                            <strong className="text-destructive">{t('projectHealth.lateWord')}</strong>
                                            {t('projectHealth.projectionSuffix')}
                                        </>
                                    ) : (
                                        <>
                                            {t('projectHealth.targetProjection', { date: formatDate(health.targetDate) })}
                                        </>
                                    )}
                                </p>
                                <form className="grid gap-2" onSubmit={saveTargetDate}>
                                    <label className="grid gap-1.5 text-sm" htmlFor="target-date">
                                        <span className="text-muted-foreground">{t('projectForm.targetDate')}</span>
                                        <Input
                                            id="target-date"
                                            data-test="target-date-input"
                                            type="date"
                                            value={targetForm.data.target_date}
                                            onChange={(e) => targetForm.setData('target_date', e.target.value)}
                                            aria-invalid={targetForm.errors.target_date ? true : undefined}
                                        />
                                    </label>
                                    {targetForm.errors.target_date ? (
                                        <p className="text-sm text-destructive">{targetForm.errors.target_date}</p>
                                    ) : null}
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        size="sm"
                                        data-test="recalculate-projection"
                                        disabled={targetForm.processing}
                                    >
                                        {t('projectHealth.recalculate')}
                                    </Button>
                                </form>
                            </CardContent>
                        </Card>
                    </div>
                </>
            ) : null}

            <Dialog open={exporting} onOpenChange={setExporting}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('projectHealth.export.title')}</DialogTitle>
                        <DialogDescription>
                            {t('projectHealth.export.description', { name: project.name })}
                        </DialogDescription>
                    </DialogHeader>
                    <form className="grid gap-4" onSubmit={exportTimeEntries}>
                        <div className="grid gap-2">
                            <Label htmlFor="time-export-format">{t('projectHealth.export.format')}</Label>
                            <Select
                                value={timeExport.format}
                                onValueChange={(value) =>
                                    value && setTimeExport((state) => ({ ...state, format: value }))
                                }
                            >
                                <SelectTrigger id="time-export-format" data-test="time-export-format" className="w-full">
                                    <SelectValue>
                                        {timeExport.format === 'json'
                                            ? t('projectHealth.export.json')
                                            : t('projectHealth.export.csv')}
                                    </SelectValue>
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="csv">{t('projectHealth.export.csv')}</SelectItem>
                                    <SelectItem value="json">{t('projectHealth.export.json')}</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <label className="grid gap-1.5 text-sm" htmlFor="time-export-after">
                                <span className="text-muted-foreground">
                                    {t('projectHealth.export.workedAfter')}
                                </span>
                                <Input
                                    id="time-export-after"
                                    type="date"
                                    data-test="time-export-after"
                                    value={timeExport.after}
                                    onChange={(event) =>
                                        setTimeExport((state) => ({ ...state, after: event.target.value }))
                                    }
                                />
                            </label>
                            <label className="grid gap-1.5 text-sm" htmlFor="time-export-before">
                                <span className="text-muted-foreground">
                                    {t('projectHealth.export.workedBefore')}
                                </span>
                                <Input
                                    id="time-export-before"
                                    type="date"
                                    data-test="time-export-before"
                                    value={timeExport.before}
                                    onChange={(event) =>
                                        setTimeExport((state) => ({ ...state, before: event.target.value }))
                                    }
                                />
                            </label>
                        </div>
                        {exportError ? (
                            <p role="alert" data-test="export-error" className="text-sm text-destructive">
                                {exportError}
                            </p>
                        ) : null}
                        <DialogFooter>
                            <Button type="submit" data-test="time-export-submit">
                                <Download aria-hidden />
                                {t('projectHealth.export.submit')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

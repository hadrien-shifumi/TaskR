import { ProjectMenu } from '@/components/project-menu';
import { ProjectViewSwitcher } from '@/components/project-view-switcher';
import { HealthBadge, PriorityBadge, StatusBadge } from '@/components/issue-badges';
import { Progress, type ProgressColor } from '@/components/progress';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { ArrowLeft } from 'lucide-react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import type { HealthData } from '@/types';
import { formatDate } from '@/lib/format-date';

interface LateIssue {
    id: string;
    title: string;
    priority: string;
    dueDate: string | null;
    statusName: string;
    statusCategory: string;
}

type PageProps = {
    project: {
        id: string;
        name: string;
        description: string | null;
        targetDate: string | null;
    };
    health: HealthData;
    lateIssues: LateIssue[];
};

/** Hours render with at most one decimal: 8 → "8", 142.5 → "142.5". */
function formatHours(hours: number): string {
    return hours.toFixed(1).replace(/\.0$/, '');
}

export default function Health() {
    const { project, health, lateIssues } = usePage<PageProps>().props;
    const { t } = useTranslation();

    const targetForm = useForm({
        name: project.name,
        description: project.description ?? '',
        target_date: project.targetDate ?? '',
    });

    const saveTargetDate = (event: React.FormEvent) => {
        event.preventDefault();
        // Health is always computed at read time: saving the new target date
        // recalculates the projection, and the fresh page lands back here.
        targetForm.patch(route('projects.update', project.id), {
            onSuccess: () => router.get(route('projects.health', project.id)),
        });
    };

    // The maquette shows whole percentages (71 %, 94 %): round at display time.
    const completion = health.completionPercentage !== null ? Math.round(health.completionPercentage) : null;
    const expected = health.expectedPercentage !== null ? Math.round(health.expectedPercentage) : null;
    const paceGap = completion !== null && expected !== null ? expected - completion : null;
    const targetPassed =
        project.targetDate !== null && project.targetDate < new Date().toISOString().slice(0, 10);

    const meters: { key: string; label: string; value: number; color: ProgressColor }[] = [
        { key: 'done', label: t('statusCategory.done'), value: health.issuesByCategory.done, color: 'success' },
        { key: 'in_progress', label: t('statusCategory.in_progress'), value: health.issuesByCategory.in_progress, color: 'info' },
        { key: 'backlog', label: t('statusCategory.backlog'), value: health.issuesByCategory.backlog, color: 'muted' },
        {
            key: 'cancelled',
            label: t('statusCategory.cancelled'),
            value: health.issuesByCategory.cancelled,
            color: 'muted-soft',
        },
    ];

    const statCards = [
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
    ];

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
                    <HealthBadge status={health.status} />
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
                    <ProjectViewSwitcher projectId={project.id} active="health" />
                    <ProjectMenu project={project} issuesCount={health.totalIssues} />
                </>
            }
        >
            <Head title={t('projectHealth.headTitle', { name: project.name })} />

            <p className="mb-5 max-w-[62ch] text-[15px] text-muted-foreground">
                {project.description ? `${project.description} ` : null}
                {t('projectHealth.intro')}
            </p>

            <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
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
                <Card data-test="stat-projection" className="gap-2 py-5">
                    <CardContent className="grid gap-1.5 px-5">
                        <p className="text-xs text-muted-foreground">{t('projectHealth.projection')}</p>
                        <div className="flex h-[28px] items-center gap-2">
                            <HealthBadge status={health.status} />
                        </div>
                        <p className="text-xs text-muted-foreground">
                            {expected !== null
                                ? t('projectHealth.projectionHint', { completion: completion ?? 0, expected })
                                : t('projectHealth.noProjectionHint')}
                        </p>
                    </CardContent>
                </Card>
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
                        <h2 className="text-base font-semibold">{t('projectHealth.projection')}</h2>
                        <div data-test="projection-done">
                            <div className="mb-1.5 flex items-center justify-between text-sm">
                                <span className="text-muted-foreground">{t('projectHealth.doneToDate')}</span>
                                <span className="font-mono font-semibold tabular-nums">{completion ?? 0}%</span>
                            </div>
                            <Progress value={completion ?? 0} color="success" />
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
        </AppLayout>
    );
}

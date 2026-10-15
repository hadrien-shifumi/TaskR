import { ProjectMenu } from '@/components/project-menu';
import { ProjectViewSwitcher } from '@/components/project-view-switcher';
import { HealthBadge, PriorityBadge, StatusBadge } from '@/components/issue-badges';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { ArrowLeft } from 'lucide-react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import type { HealthData } from '@/types';

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

function formatDate(date: string | null): string {
    if (!date) return '—';
    return new Date(date).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

export default function Health() {
    const { project, health, lateIssues } = usePage<PageProps>().props;

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

    const meters = [
        { key: 'done', label: 'done', value: health.issuesByCategory.done, fill: 'bg-success' },
        { key: 'in_progress', label: 'in progress', value: health.issuesByCategory.in_progress, fill: 'bg-info' },
        { key: 'backlog', label: 'backlog', value: health.issuesByCategory.backlog, fill: 'bg-muted-foreground' },
        {
            key: 'cancelled',
            label: 'cancelled',
            value: health.issuesByCategory.cancelled,
            fill: 'bg-muted-foreground/50',
        },
    ];

    const statCards = [
        {
            key: 'stat-completed',
            label: 'Completed',
            value: completion !== null ? String(completion) : '—',
            suffix: completion !== null ? ' %' : '',
            hint: `${health.issuesByCategory.done} done + ${health.issuesByCategory.cancelled} cancelled of ${health.totalIssues} issues`,
        },
        {
            key: 'stat-overdue',
            label: 'Overdue',
            value: String(health.overdueIssues),
            hint: 'Past due date, not done or cancelled',
            danger: health.overdueIssues > 0,
        },
        {
            key: 'stat-hours',
            label: 'Hours',
            value: formatHours(health.loggedHours),
            suffix: ` / ${formatHours(health.estimatedHours)} h`,
            hint:
                health.consumptionRatio !== null
                    ? `logged / estimated · ${Math.round(health.consumptionRatio)}% consumed`
                    : 'No estimates set',
        },
    ];

    return (
        <AppLayout
            leading={
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label="Back to projects"
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
                            target: {formatDate(project.targetDate)}
                            {targetPassed ? ' · overdue' : ''}
                        </span>
                    ) : null}
                    <ProjectViewSwitcher projectId={project.id} active="health" />
                    <ProjectMenu project={project} issuesCount={health.totalIssues} />
                </>
            }
        >
            <Head title={`Health — ${project.name}`} />

            <p className="mb-5 max-w-[62ch] text-[15px] text-muted-foreground">
                {project.description ? `${project.description} ` : null}
                All indicators below are calculated from the issues — nothing is entered by hand.
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
                        <p className="text-xs text-muted-foreground">Projection</p>
                        <div className="flex h-[28px] items-center gap-2">
                            <HealthBadge status={health.status} />
                        </div>
                        <p className="text-xs text-muted-foreground">
                            {expected !== null
                                ? `${completion ?? 0}% done · ${expected}% expected at this date`
                                : 'No target date — no projection'}
                        </p>
                    </CardContent>
                </Card>
            </div>

            <div className="grid items-start gap-7 lg:grid-cols-[1fr_280px]">
                <div className="grid gap-4">
                    <Card className="py-5">
                        <CardContent className="grid gap-3.5 px-5">
                            <div className="flex items-center justify-between">
                                <h2 className="text-base font-semibold">Issues distribution</h2>
                                <span className="font-mono text-xs text-muted-foreground tabular-nums">
                                    {health.totalIssues} issues
                                </span>
                            </div>
                            {meters.map((meter) => (
                                <div key={meter.key} data-test={`dist-${meter.key}`} className="flex items-center gap-2.5">
                                    <span className="w-23 flex-none">
                                        <StatusBadge name={meter.label} category={meter.key} />
                                    </span>
                                    <span className="h-1.5 flex-1 overflow-hidden rounded-full bg-secondary">
                                        <span
                                            className={`block h-full rounded-full ${meter.fill}`}
                                            style={{
                                                width: `${
                                                    health.totalIssues > 0
                                                        ? Math.round((meter.value / health.totalIssues) * 100)
                                                        : 0
                                                }%`,
                                            }}
                                        />
                                    </span>
                                    <span className="w-9 text-right font-mono text-xs tabular-nums">
                                        {meter.value}
                                    </span>
                                </div>
                            ))}
                        </CardContent>
                    </Card>

                    <div className="overflow-hidden rounded-xl border bg-card">
                        <div className="flex items-center gap-2.5 border-b px-5 py-3.5">
                            <h3 className="text-sm font-semibold">Late issues in this project</h3>
                            <div className="flex-1" />
                            <Link
                                href={route('issues.index', { project: project.id })}
                                className="text-sm text-muted-foreground hover:text-foreground"
                            >
                                All issues
                            </Link>
                        </div>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Title</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Priority</TableHead>
                                    <TableHead>Due date</TableHead>
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
                                            No late issue — every open issue is within its due date.
                                        </TableCell>
                                    </TableRow>
                                ) : null}
                            </TableBody>
                        </Table>
                    </div>
                </div>

                <Card className="py-5">
                    <CardContent className="grid gap-3.5 px-5">
                        <h2 className="text-base font-semibold">Projection</h2>
                        <div data-test="projection-done">
                            <div className="mb-1.5 flex items-center justify-between text-sm">
                                <span className="text-muted-foreground">Done to date</span>
                                <span className="font-mono font-semibold tabular-nums">{completion ?? 0}%</span>
                            </div>
                            <span className="block h-1.5 overflow-hidden rounded-full bg-secondary">
                                <span
                                    className="block h-full rounded-full bg-success"
                                    style={{ width: `${completion ?? 0}%` }}
                                />
                            </span>
                        </div>
                        <div data-test="projection-expected">
                            <div className="mb-1.5 flex items-center justify-between text-sm">
                                <span className="text-muted-foreground">Expected to date</span>
                                <span className="font-mono font-semibold tabular-nums">
                                    {expected !== null ? `${expected}%` : '—'}
                                </span>
                            </div>
                            <span className="block h-1.5 overflow-hidden rounded-full bg-secondary">
                                <span
                                    className={`block h-full rounded-full ${
                                        health.status === 'late' ? 'bg-destructive' : 'bg-muted-foreground'
                                    }`}
                                    style={{ width: `${expected ?? 0}%` }}
                                />
                            </span>
                        </div>
                        <hr className="border-border" />
                        <p className="text-[12.5px] text-muted-foreground">
                            {health.targetDate === null ? (
                                'Set a target date to activate the projection: the expected pace is derived from the project start and the target.'
                            ) : health.status === 'late' && paceGap !== null ? (
                                <>
                                    {`The target date of ${formatDate(health.targetDate)} is past with a ${paceGap}-point gap on the expected pace: the projection is `}
                                    <strong className="text-destructive">Late</strong>
                                    {'. Moving the target date or closing the remaining issues recalculates the projection immediately.'}
                                </>
                            ) : (
                                <>
                                    Target date {formatDate(health.targetDate)}. Moving the target date or closing
                                    the remaining issues recalculates the projection immediately.
                                </>
                            )}
                        </p>
                        <form className="grid gap-2" onSubmit={saveTargetDate}>
                            <label className="grid gap-1.5 text-sm" htmlFor="target-date">
                                <span className="text-muted-foreground">Target date</span>
                                <Input
                                    id="target-date"
                                    data-test="target-date-input"
                                    type="date"
                                    value={targetForm.data.target_date}
                                    onChange={(e) => targetForm.setData('target_date', e.target.value)}
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
                                Recalculate projection
                            </Button>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}

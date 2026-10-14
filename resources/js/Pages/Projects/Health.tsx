import AppLayout from '@/layouts/app-layout';
import { HealthBadge } from '@/components/issue-badges';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Head, Link, router, usePage } from '@inertiajs/react';

interface ProjectInfo {
    id: string;
    name: string;
    description: string | null;
    targetDate: string | null;
    createdAt: string | null;
}

interface HealthData {
    issuesByCategory: { backlog: number; in_progress: number; done: number; cancelled: number };
    totalIssues: number;
    completionPercentage: number | null;
    overdueIssues: number;
    estimatedHours: number;
    loggedHours: number;
    consumptionRatio: number | null;
    expectedPercentage: number | null;
    status: string | null;
    targetDate: string | null;
}

type PageProps = {
    project: ProjectInfo;
    health: HealthData;
};

function formatHours(hours: number): string {
    return `${hours.toFixed(1)}h`;
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
    const { project, health } = usePage<PageProps>().props;

    const meters = [
        { key: 'backlog', label: 'Backlog', value: health.issuesByCategory.backlog, fill: 'bg-muted-foreground' },
        { key: 'in_progress', label: 'In Progress', value: health.issuesByCategory.in_progress, fill: 'bg-info' },
        { key: 'done', label: 'Done', value: health.issuesByCategory.done, fill: 'bg-success' },
        { key: 'cancelled', label: 'Cancelled', value: health.issuesByCategory.cancelled, fill: 'bg-muted-foreground/70' },
    ];

    return (
        <AppLayout title={`Health — ${project.name}`}>
            <Head title={`Health — ${project.name}`} />

            <div className="mb-4 flex flex-wrap items-center gap-3">
                <Button variant="ghost" size="sm" onClick={() => router.get(route('projects.index'))}>
                    ← Projects
                </Button>
                <div className="inline-flex items-center gap-0.5 rounded-full border bg-card p-0.5">
                    <span
                        aria-current="page"
                        className="inline-flex h-6.5 items-center rounded-full bg-secondary px-3 text-xs font-medium"
                    >
                        Health
                    </span>
                    <Link
                        href={route('projects.board', project.id)}
                        className="inline-flex h-6.5 items-center rounded-full px-3 text-xs font-medium text-muted-foreground hover:bg-accent hover:text-foreground"
                    >
                        Kanban
                    </Link>
                </div>
            </div>

            <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 className="text-xl font-semibold">{project.name}</h2>
                    {project.description ? (
                        <p className="mt-1 max-w-xl text-sm text-muted-foreground">{project.description}</p>
                    ) : null}
                    <p className="mt-1 text-sm text-muted-foreground">
                        <span className="font-mono tabular-nums">{health.totalIssues}</span> issues · target
                        date: {formatDate(project.targetDate)}
                    </p>
                </div>
                <HealthBadge status={health.status} />
            </div>

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {meters.map((meter) => (
                    <Card key={meter.key} className="gap-2 py-5">
                        <CardHeader className="px-5">
                            <CardDescription>{meter.label}</CardDescription>
                            <CardTitle className="font-mono text-2xl tabular-nums">{meter.value}</CardTitle>
                        </CardHeader>
                        <CardContent className="px-5">
                            <div className="h-1.5 overflow-hidden rounded-full bg-secondary">
                                <div
                                    className={`h-full rounded-full ${meter.fill}`}
                                    style={{
                                        width: `${
                                            health.totalIssues > 0
                                                ? Math.round((meter.value / health.totalIssues) * 100)
                                                : 0
                                        }%`,
                                    }}
                                />
                            </div>
                        </CardContent>
                    </Card>
                ))}
            </div>

            <div className="mt-4 grid gap-4 sm:grid-cols-2">
                <Card className="gap-2 py-5">
                    <CardHeader className="px-5">
                        <CardDescription>Completion</CardDescription>
                        <CardTitle className="font-mono text-2xl tabular-nums">
                            {health.completionPercentage !== null ? `${health.completionPercentage}%` : '—'}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="px-5">
                        <div className="h-1.5 overflow-hidden rounded-full bg-secondary">
                            <div
                                className="h-full rounded-full bg-success"
                                style={{ width: `${health.completionPercentage ?? 0}%` }}
                            />
                        </div>
                        <p className="mt-2 text-sm text-muted-foreground">
                            {health.totalIssues} issue{health.totalIssues === 1 ? '' : 's'} total
                            {health.expectedPercentage !== null
                                ? ` · expected at this date: ${health.expectedPercentage}%`
                                : ''}
                        </p>
                    </CardContent>
                </Card>

                <Card className="gap-2 py-5">
                    <CardHeader className="px-5">
                        <CardDescription>Overdue issues</CardDescription>
                        <CardTitle
                            className={`font-mono text-2xl tabular-nums ${
                                health.overdueIssues > 0 ? 'text-destructive' : ''
                            }`}
                        >
                            {health.overdueIssues}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="px-5">
                        <p className="text-sm text-muted-foreground">
                            Past due date, not done or cancelled
                        </p>
                    </CardContent>
                </Card>
            </div>

            <Card className="mt-4 gap-2 py-5">
                <CardHeader className="px-5">
                    <CardDescription>Time tracking</CardDescription>
                    <CardTitle className="font-mono text-2xl tabular-nums">
                        {formatHours(health.loggedHours)} / {formatHours(health.estimatedHours)}
                    </CardTitle>
                </CardHeader>
                <CardContent className="px-5">
                    {health.consumptionRatio !== null ? (
                        <>
                            <div className="h-1.5 overflow-hidden rounded-full bg-secondary">
                                <div
                                    className={`h-full rounded-full ${
                                        health.consumptionRatio > 100 ? 'bg-destructive' : 'bg-primary'
                                    }`}
                                    style={{ width: `${Math.min(health.consumptionRatio, 100)}%` }}
                                />
                            </div>
                            <p className="mt-2 text-sm text-muted-foreground">
                                {health.consumptionRatio}% of estimated hours consumed
                            </p>
                        </>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            No estimates set — consumption ratio unavailable
                        </p>
                    )}
                </CardContent>
            </Card>

            {health.status && health.expectedPercentage !== null ? (
                <Card className="mt-4 gap-2 py-5">
                    <CardHeader className="px-5">
                        <CardDescription>Projection</CardDescription>
                    </CardHeader>
                    <CardContent className="px-5">
                        <p className="text-sm">
                            Completed: <strong>{health.completionPercentage ?? 0}%</strong>
                            {' — '}
                            Expected at this date: <strong>{health.expectedPercentage}%</strong>
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Formula: (today − created_at) / (target_date − created_at)
                        </p>
                    </CardContent>
                </Card>
            ) : null}
        </AppLayout>
    );
}

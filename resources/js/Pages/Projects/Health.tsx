import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Head, router, usePage } from '@inertiajs/react';

interface ProjectInfo {
    id: string;
    name: string;
    description: string | null;
    targetDate: string | null;
    createdAt: string | null;
}

interface HealthData {
    issuesByCategory: {
        backlog: number;
        in_progress: number;
        done: number;
        cancelled: number;
    };
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

function statusBadgeVariant(status: string | null): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (status === 'late') return 'destructive';
    if (status === 'at-risk') return 'secondary';
    return 'default';
}

function statusLabel(status: string | null): string {
    if (status === 'ok') return 'On track';
    if (status === 'at-risk') return 'At risk';
    if (status === 'late') return 'Late';
    return 'No projection';
}

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

    const categories = [
        { key: 'backlog', label: 'Backlog', value: health.issuesByCategory.backlog },
        { key: 'in_progress', label: 'In Progress', value: health.issuesByCategory.in_progress },
        { key: 'done', label: 'Done', value: health.issuesByCategory.done },
        { key: 'cancelled', label: 'Cancelled', value: health.issuesByCategory.cancelled },
    ];

    return (
        <AppLayout title={`Health — ${project.name}`}>
            <Head title={`Health — ${project.name}`} />

            <div className="mb-4">
                <Button variant="ghost" size="sm" onClick={() => router.get(route('projects.index'))}>
                    ← Back to projects
                </Button>
            </div>

            <div className="mb-6 flex items-start justify-between gap-4">
                <div>
                    <h2 className="text-xl font-semibold">{project.name}</h2>
                    {project.description ? (
                        <p className="mt-1 text-sm text-muted-foreground">{project.description}</p>
                    ) : null}
                    <p className="mt-1 text-sm text-muted-foreground">
                        Target date: {formatDate(project.targetDate)}
                    </p>
                </div>
                {health.status ? (
                    <Badge variant={statusBadgeVariant(health.status)} className="text-sm">
                        {statusLabel(health.status)}
                    </Badge>
                ) : (
                    <Badge variant="outline" className="text-sm">
                        No target date
                    </Badge>
                )}
            </div>

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {categories.map((category) => (
                    <Card key={category.key}>
                        <CardHeader>
                            <CardDescription>{category.label}</CardDescription>
                            <CardTitle className="text-2xl">{category.value}</CardTitle>
                        </CardHeader>
                    </Card>
                ))}
            </div>

            <div className="mt-6 grid gap-4 sm:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardDescription>Completion</CardDescription>
                        <CardTitle className="text-2xl">
                            {health.completionPercentage !== null
                                ? `${health.completionPercentage}%`
                                : '—'}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p className="text-sm text-muted-foreground">
                            {health.totalIssues} issue{health.totalIssues === 1 ? '' : 's'} total
                        </p>
                        {health.expectedPercentage !== null ? (
                            <p className="text-sm text-muted-foreground">
                                Expected at this date: {health.expectedPercentage}%
                            </p>
                        ) : null}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardDescription>Overdue issues</CardDescription>
                        <CardTitle className={`text-2xl ${health.overdueIssues > 0 ? 'text-destructive' : ''}`}>
                            {health.overdueIssues}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p className="text-sm text-muted-foreground">
                            Past due date, not done or cancelled
                        </p>
                    </CardContent>
                </Card>
            </div>

            <Card className="mt-4">
                <CardHeader>
                    <CardDescription>Time tracking</CardDescription>
                    <CardTitle className="text-2xl">
                        {formatHours(health.loggedHours)} / {formatHours(health.estimatedHours)}
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    {health.consumptionRatio !== null ? (
                        <p className="text-sm text-muted-foreground">
                            {health.consumptionRatio}% of estimated hours consumed
                        </p>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            No estimates set — consumption ratio unavailable
                        </p>
                    )}
                </CardContent>
            </Card>

            {health.status && health.expectedPercentage !== null ? (
                <Card className="mt-4">
                    <CardHeader>
                        <CardDescription>Projection</CardDescription>
                    </CardHeader>
                    <CardContent>
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

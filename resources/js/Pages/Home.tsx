import AppLayout from '@/layouts/app-layout';
import { HealthBadge, PriorityBadge, StatusBadge } from '@/components/issue-badges';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Head, Link, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { QuickCreateIssueDialog } from '@/components/quick-create-issue-dialog';

interface HealthData {
    issuesByCategory: { backlog: number; in_progress: number; done: number; cancelled: number };
    totalIssues: number;
    completionPercentage: number | null;
    overdueIssues: number;
    expectedPercentage: number | null;
    status: string | null;
    targetDate: string | null;
}

interface ProjectCard {
    id: string;
    name: string;
    health: HealthData;
}

interface MyIssue {
    id: string;
    title: string;
    projectName: string;
    statusName: string;
    statusCategory: string;
    priority: string;
    dueDate: string | null;
    overdue: boolean;
}

type PageProps = {
    appName: string;
    stats: {
        totalIssues: number;
        openIssues: number;
        overdueIssues: number;
        loggedHoursThisWeek: number;
        activeProjects: number;
        archivedProjects: number;
    };
    projects: ProjectCard[];
    myIssues: MyIssue[];
};

function formatDate(date: string | null): string {
    if (!date) return '—';
    return new Date(date).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

export default function Home() {
    const { appName, stats, projects, myIssues } = usePage<PageProps>().props;
    const [creating, setCreating] = useState(false);

    const statCards: { label: string; value: ReactNode; hint: string; danger?: boolean }[] = [
        { label: 'Open issues', value: stats.openIssues, hint: `out of ${stats.totalIssues} total` },
        {
            label: 'Overdue issues',
            value: stats.overdueIssues,
            hint: 'past due date, not done or cancelled',
            danger: stats.overdueIssues > 0,
        },
        {
            label: 'Hours logged',
            value: (
                <>
                    {stats.loggedHoursThisWeek}
                    <span className="text-[15px] text-muted-foreground"> h</span>
                </>
            ),
            hint: 'this week, all projects',
        },
        {
            label: 'Active projects',
            value: stats.activeProjects,
            hint:
                stats.archivedProjects > 0
                    ? `+ ${stats.archivedProjects} archived`
                    : 'no archived project',
        },
    ];

    return (
        <AppLayout
            title="Home"
            subtitle="Workspace overview"
            actions={
                projects.length > 0 ? (
                    <Button size="sm" variant="outline" onClick={() => setCreating(true)}>
                        <Plus />
                        New issue
                    </Button>
                ) : null
            }
        >
            <Head title="Home" />

            <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                {statCards.map((card) => (
                    <Card key={card.label} className="gap-2 py-5">
                        <CardDescription className="px-5">{card.label}</CardDescription>
                        <CardContent className="px-5">
                            <p
                                className={`font-mono text-[28px] leading-tight font-semibold tracking-tight tabular-nums ${
                                    card.danger ? 'text-destructive' : ''
                                }`}
                            >
                                {card.value}
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">{card.hint}</p>
                        </CardContent>
                    </Card>
                ))}
            </div>

            <section className="mt-6">
                <div className="mb-3 flex items-center justify-between">
                    <h2 className="text-base font-semibold">Projects health</h2>
                    <Button variant="ghost" size="sm" render={<Link href={route('projects.index')} />}>
                        All projects
                    </Button>
                </div>
                {projects.length > 0 ? (
                    <div className="grid gap-4 sm:grid-cols-2">
                        {projects.map((project) => (
                            <Link
                                key={project.id}
                                href={route('projects.health', project.id)}
                                className="flex flex-col gap-2.5 rounded-xl border bg-card p-4.5 transition-colors hover:border-primary/45"
                            >
                                <div className="flex items-center justify-between gap-2">
                                    <h3 className="font-semibold">{project.name}</h3>
                                    <HealthBadge status={project.health.status} />
                                </div>
                                <div className="h-1.5 overflow-hidden rounded-full bg-secondary">
                                    <div
                                        className="h-full rounded-full bg-success"
                                        style={{ width: `${project.health.completionPercentage ?? 0}%` }}
                                    />
                                </div>
                                <div className="flex items-center justify-between text-sm text-muted-foreground">
                                    <span>{project.health.completionPercentage ?? 0}% complete</span>
                                    <span className="font-mono text-xs tabular-nums">
                                        {project.health.targetDate
                                            ? `target: ${formatDate(project.health.targetDate)}`
                                            : 'no target date'}
                                    </span>
                                </div>
                            </Link>
                        ))}
                    </div>
                ) : (
                    <p className="rounded-xl border border-dashed px-4 py-8 text-center text-sm text-muted-foreground">
                        No active project yet — create one from the projects page.
                    </p>
                )}
            </section>

            <section className="mt-6 overflow-hidden rounded-xl border bg-card">
                <div className="flex items-center gap-2.5 border-b px-5 py-3.5">
                    <h3 className="text-sm font-semibold">My issues</h3>
                    <div className="flex-1" />
                    <Button variant="ghost" size="sm" render={<Link href={route('issues.index')} />}>
                        View all issues
                    </Button>
                </div>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Title</TableHead>
                            <TableHead>Project</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Priority</TableHead>
                            <TableHead>Due date</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {myIssues.map((issue) => (
                            <TableRow key={issue.id}>
                                <TableCell>
                                    <Link href={route('issues.show', issue.id)} className="font-medium hover:text-primary">
                                        {issue.title}
                                    </Link>
                                </TableCell>
                                <TableCell className="text-muted-foreground">{issue.projectName}</TableCell>
                                <TableCell>
                                    <StatusBadge name={issue.statusName} category={issue.statusCategory} />
                                </TableCell>
                                <TableCell>
                                    <PriorityBadge priority={issue.priority} />
                                </TableCell>
                                <TableCell
                                    className={`font-mono text-xs tabular-nums ${
                                        issue.overdue ? 'font-medium text-destructive' : 'text-muted-foreground'
                                    }`}
                                >
                                    {issue.dueDate ? formatDate(issue.dueDate) : '—'}
                                </TableCell>
                            </TableRow>
                        ))}
                        {myIssues.length === 0 ? (
                            <TableRow>
                                <TableCell colSpan={5} className="py-6 text-center text-muted-foreground">
                                    No issue assigned to you yet.
                                </TableCell>
                            </TableRow>
                        ) : null}
                    </TableBody>
                </Table>
            </section>

            <QuickCreateIssueDialog
                open={creating}
                onClose={() => setCreating(false)}
                projects={projects.map((project) => ({ id: project.id, name: project.name }))}
            />
        </AppLayout>
    );
}

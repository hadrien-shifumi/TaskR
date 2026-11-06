import AppLayout from '@/layouts/app-layout';
import { HealthBadge, PriorityBadge, StatusBadge } from '@/components/issue-badges';
import { Progress } from '@/components/progress';
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
import { useTranslation } from 'react-i18next';
import { QuickCreateIssueDialog } from '@/components/quick-create-issue-dialog';
import { formatDate } from '@/lib/format-date';

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

export default function Home() {
    const { appName, stats, projects, myIssues } = usePage<PageProps>().props;
    const { t } = useTranslation();
    const [creating, setCreating] = useState(false);

    const statCards: { label: string; value: ReactNode; hint: string; danger?: boolean }[] = [
        { label: t('home.stats.openIssues'), value: stats.openIssues, hint: t('home.stats.openIssuesHint', { total: stats.totalIssues }) },
        {
            label: t('home.stats.overdue'),
            value: stats.overdueIssues,
            hint: t('home.stats.overdueHint'),
            danger: stats.overdueIssues > 0,
        },
        {
            label: t('home.stats.hoursLogged'),
            value: (
                <>
                    {stats.loggedHoursThisWeek}
                    <span className="text-[15px] text-muted-foreground"> h</span>
                </>
            ),
            hint: t('home.stats.hoursHint'),
        },
        {
            label: t('home.stats.activeProjects'),
            value: stats.activeProjects,
            hint:
                stats.archivedProjects > 0
                    ? t('home.stats.archivedHint', { count: stats.archivedProjects })
                    : t('home.stats.noArchivedHint'),
        },
    ];

    return (
        <AppLayout
            title={t('home.title')}
            subtitle={t('home.subtitle')}
            actions={
                projects.length > 0 ? (
                    <Button size="sm" variant="outline" onClick={() => setCreating(true)}>
                        <Plus />
                        {t('home.newIssue')}
                    </Button>
                ) : null
            }
        >
            <Head title={t('home.title')} />

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
                    <h2 className="text-base font-semibold">{t('home.projectsHealth')}</h2>
                    <Button variant="ghost" size="sm" render={<Link href={route('projects.index')} />}>
                        {t('home.allProjects')}
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
                                <Progress
                                    value={project.health.completionPercentage ?? 0}
                                    color="success"
                                />
                                <div className="flex items-center justify-between text-sm text-muted-foreground">
                                    <span>{t('home.complete', { percent: project.health.completionPercentage ?? 0 })}</span>
                                    <span className="font-mono text-xs tabular-nums">
                                        {project.health.targetDate
                                            ? t('home.target', { date: formatDate(project.health.targetDate) })
                                            : t('home.noTargetDate')}
                                    </span>
                                </div>
                            </Link>
                        ))}
                    </div>
                ) : (
                    <p className="rounded-xl border border-dashed px-4 py-8 text-center text-sm text-muted-foreground">
                        {t('home.noActiveProject')}
                    </p>
                )}
            </section>

            <section className="mt-6 overflow-hidden rounded-xl border bg-card">
                <div className="flex items-center gap-2.5 border-b px-5 py-3.5">
                    <h3 className="text-sm font-semibold">{t('home.myIssues')}</h3>
                    <div className="flex-1" />
                    <Button variant="ghost" size="sm" render={<Link href={route('issues.index')} />}>
                        {t('home.viewAllIssues')}
                    </Button>
                </div>
                {myIssues.length === 0 ? (
                    <p className="px-5 py-6 text-center text-muted-foreground">
                        {t('home.noAssignedIssues')}
                    </p>
                ) : (
                    <>
                        {/* Mobile: a compact list, no table scroll. */}
                        <ul className="divide-y md:hidden" data-test="my-issues-mobile-list">
                            {myIssues.map((issue) => (
                                <li key={issue.id} className="flex items-center gap-2.5 px-4 py-3">
                                    <Link
                                        href={route('issues.show', issue.id)}
                                        className="min-w-0 flex-1 truncate text-sm font-medium hover:text-primary"
                                    >
                                        {issue.title}
                                    </Link>
                                    <StatusBadge name={issue.statusName} category={issue.statusCategory} />
                                    {issue.dueDate ? (
                                        <span
                                            className={`font-mono text-xs tabular-nums ${
                                                issue.overdue ? 'font-medium text-destructive' : 'text-muted-foreground'
                                            }`}
                                        >
                                            {formatDate(issue.dueDate)}
                                        </span>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                        {/* Desktop: the table, unchanged. */}
                        <div className="hidden md:block">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>{t('columns.title')}</TableHead>
                                        <TableHead>{t('columns.project')}</TableHead>
                                        <TableHead>{t('columns.status')}</TableHead>
                                        <TableHead>{t('columns.priority')}</TableHead>
                                        <TableHead>{t('columns.dueDate')}</TableHead>
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
                                </TableBody>
                            </Table>
                        </div>
                    </>
                )}
            </section>

            <QuickCreateIssueDialog
                open={creating}
                onClose={() => setCreating(false)}
                projects={projects.map((project) => ({ id: project.id, name: project.name }))}
            />
        </AppLayout>
    );
}

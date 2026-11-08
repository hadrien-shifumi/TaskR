import { ProjectMenu } from '@/components/project-menu';
import { ProjectViewSwitcher } from '@/components/project-view-switcher';
import { Avatar, PriorityBadge, StatusBadge } from '@/components/issue-badges';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { ActivitySection } from '@/Pages/Issues/Show/components/activity-section';
import type { ActivityEvent } from '@/Pages/Issues/Show/types';
import { ArrowLeft, ChevronLeft, ChevronRight } from 'lucide-react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { formatDate } from '@/lib/format-date';

interface AssignedIssue {
    id: string;
    title: string;
    statusId: string;
    statusName: string;
    statusCategory: string;
    priority: string;
    dueDate: string | null;
    overdue: boolean;
}

type PageProps = {
    project: {
        id: string;
        name: string;
        description: string | null;
        targetDate: string | null;
    };
    member: { id: number; name: string };
    assignedIssues: AssignedIssue[];
    issuesCount: number;
    events: ActivityEvent[];
    pagination: {
        currentPage: number;
        lastPage: number;
        perPage: number;
        total: number;
        from: number | null;
        to: number | null;
    };
};

export default function MemberActivity() {
    const { project, member, assignedIssues, issuesCount, events, pagination } = usePage<PageProps>().props;
    const { t } = useTranslation();

    /** Navigates to a page of the member's journal, preserving the page
     *  position across pagination. */
    const goToPage = (page: number) => {
        router.get(
            route('projects.memberActivity', { project: project.id, member: member.id }),
            { page: String(page) },
            {
                preserveState: true,
                // Only the journal and its pagination refetch.
                only: ['events', 'pagination'],
            },
        );
    };

    return (
        <AppLayout
            leading={
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label={t('projectHealth.backToHealth')}
                    onClick={() => router.get(route('projects.health', project.id))}
                >
                    <ArrowLeft aria-hidden />
                </Button>
            }
            title={<span>{project.name}</span>}
            actions={
                <>
                    <ProjectViewSwitcher projectId={project.id} active="activity" />
                    <ProjectMenu project={project} issuesCount={issuesCount} />
                </>
            }
        >
            <Head title={t('projectHealth.memberActivity.title', { name: member.name })} />

            <p className="mb-5 max-w-[62ch] text-[15px] text-muted-foreground">
                {t('projectHealth.memberActivity.intro', { member: member.name })}
            </p>

            {/* What the member is working on right now */}
            <Card className="py-5" data-test="member-work">
                <CardContent className="grid gap-3.5 px-5">
                    <div className="flex items-center justify-between gap-2">
                        <h2 className="text-base font-semibold">{t('projectHealth.memberActivity.currentWork')}</h2>
                        <span className="font-mono text-xs text-muted-foreground tabular-nums">
                            {t('projectHealth.issuesCount', { count: assignedIssues.length })}
                        </span>
                    </div>
                    {assignedIssues.length === 0 ? (
                        <p className="text-sm text-muted-foreground" data-test="member-work-empty">
                            {t('projectHealth.memberActivity.noWork')}
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {assignedIssues.map((issue) => (
                                <li
                                    key={issue.id}
                                    data-test="member-work-issue"
                                    className="flex flex-wrap items-center gap-2.5 py-3"
                                >
                                    <StatusBadge name={issue.statusName} category={issue.statusCategory} />
                                    <Link href={route('issues.show', issue.id)} className="font-medium hover:text-primary">
                                        {issue.title}
                                    </Link>
                                    <PriorityBadge priority={issue.priority} />
                                    {issue.dueDate ? (
                                        <span
                                            className={`font-mono text-xs font-medium tabular-nums ${
                                                issue.overdue ? 'text-destructive' : 'text-muted-foreground'
                                            }`}
                                        >
                                            {formatDate(issue.dueDate)}
                                        </span>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                    )}
                </CardContent>
            </Card>

            {/* Activity of the member on this project */}
            <Card className="py-5" data-test="activity-events">
                <CardContent className="px-5">
                    <ActivitySection events={events} />
                </CardContent>
            </Card>

            {pagination.lastPage > 1 && (
                <div className="mt-4 flex items-center justify-end gap-2" data-test="activity-pagination">
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={pagination.currentPage <= 1}
                        onClick={() => goToPage(pagination.currentPage - 1)}
                        data-test="activity-pagination-prev"
                    >
                        <ChevronLeft aria-hidden />
                        {t('audit.pagination.previous')}
                    </Button>
                    <span className="text-sm text-muted-foreground" data-test="activity-pagination-counter">
                        {t('audit.pagination.page', {
                            current: pagination.currentPage,
                            last: pagination.lastPage,
                            total: pagination.total,
                        })}
                    </span>
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={pagination.currentPage >= pagination.lastPage}
                        onClick={() => goToPage(pagination.currentPage + 1)}
                        data-test="activity-pagination-next"
                    >
                        <ChevronRight aria-hidden />
                        {t('audit.pagination.next')}
                    </Button>
                </div>
            )}
        </AppLayout>
    );
}
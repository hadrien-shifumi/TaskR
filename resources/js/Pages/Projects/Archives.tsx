import { ProjectMenu } from '@/components/project-menu';
import { ProjectViewSwitcher } from '@/components/project-view-switcher';
import { AvatarStack, PriorityBadge, StatusBadge } from '@/components/issue-badges';
import { FilterSelect } from '@/components/filter-select';
import { FlashBanner } from '@/components/flash-banner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { ArrowLeft, ArchiveRestore, ChevronLeft, ChevronRight } from 'lucide-react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { formatShortDate } from '@/lib/format-date';

interface ArchivedIssue {
    id: string;
    title: string;
    project: { id: string; name: string };
    status: { id: string; name: string; category: string };
    priority: string;
    dueDate: string | null;
    overdue: boolean;
    assignees: { id: number; name: string }[];
    labels: { id: string; name: string; color: string }[];
    archivedAt: string;
}

type PageProps = {
    project: {
        id: string;
        name: string;
        description: string | null;
        targetDate: string | null;
    };
    issues: ArchivedIssue[];
    pagination: {
        currentPage: number;
        lastPage: number;
        perPage: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    filters: {
        status: string;
        priority: string;
        assignee: string;
        archivedFrom: string;
        archivedTo: string;
    };
    statuses: { id: string; name: string; category: string }[];
    members: { id: number; name: string }[];
    canUnarchive: boolean;
};

const PRIORITY_OPTIONS = ['none', 'low', 'high', 'urgent'] as const;

/** The archive of a project: read-only history, unarchiving is reserved
 * to the workspace owner (the toggle cascades back to the subtree). */
export default function Archives() {
    const { project, issues, pagination, filters, statuses, members, canUnarchive } =
        usePage<PageProps>().props;
    const { t } = useTranslation();

    const unarchive = (issueId: string) => {
        router.post(route('issues.archive', issueId));
    };

    /** Sets one URL filter; an empty value removes the param and resets
     *  to page 1 so filtered URLs stay clean and shareable. */
    const setFilter = (key: string, value: string) => {
        const next: Record<string, string> = {
            status: filters.status || undefined,
            priority: filters.priority || undefined,
            assignee: filters.assignee || undefined,
            archived_from: filters.archivedFrom || undefined,
            archived_to: filters.archivedTo || undefined,
        };
        if (value === '') {
            delete next[key];
        } else {
            next[key] = value;
        }
        const cleaned = Object.fromEntries(
            Object.entries(next).filter(([_, v]) => v !== undefined && v !== ''),
        );
        router.get(route('projects.archives', project.id), cleaned, { preserveState: true });
    };

    /** Navigates to a page, keeping the active filters. */
    const goToPage = (page: number) => {
        const params: Record<string, string> = { page: String(page) };
        if (filters.status) params.status = filters.status;
        if (filters.priority) params.priority = filters.priority;
        if (filters.assignee) params.assignee = filters.assignee;
        if (filters.archivedFrom) params.archived_from = filters.archivedFrom;
        if (filters.archivedTo) params.archived_to = filters.archivedTo;
        router.get(route('projects.archives', project.id), params, { preserveState: true });
    };

    const hasActiveFilters =
        filters.status !== '' ||
        filters.priority !== '' ||
        filters.assignee !== '' ||
        filters.archivedFrom !== '' ||
        filters.archivedTo !== '';

    const statusOptions = statuses.map((status) => ({
        value: status.id,
        label: status.name,
    }));

    const priorityOptions = PRIORITY_OPTIONS.map((priority) => ({
        value: priority,
        label: t(`priority.${priority}`),
    }));

    const assigneeOptions = members.map((member) => ({
        value: String(member.id),
        label: member.name,
    }));

    return (
        <AppLayout
            leading={
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label={t('projectArchives.backToBoard')}
                    onClick={() => router.get(route('projects.board', project.id))}
                >
                    <ArrowLeft aria-hidden />
                </Button>
            }
            title={<span>{t('projectArchives.title', { name: project.name })}</span>}
            actions={
                <>
                    <ProjectViewSwitcher projectId={project.id} active="board" />
                    <ProjectMenu project={project} issuesCount={0} />
                </>
            }
        >
            <Head title={t('projectArchives.headTitle', { name: project.name })} />

            <p className="mb-4 text-sm text-muted-foreground">{t('projectArchives.intro')}</p>

            <FlashBanner />

            <div
                className="mb-3.5 flex flex-wrap items-center gap-2"
                data-test="archives-filter-bar"
            >
                <FilterSelect
                    testId="archives-filter-status"
                    value={filters.status}
                    options={statusOptions}
                    allLabel={t('projectArchives.filters.allStatuses')}
                    onChange={(value) => setFilter('status', value)}
                    className="h-7.5 w-36 text-[13px]"
                />
                <FilterSelect
                    testId="archives-filter-priority"
                    value={filters.priority}
                    options={priorityOptions}
                    allLabel={t('projectArchives.filters.allPriorities')}
                    onChange={(value) => setFilter('priority', value)}
                    className="h-7.5 w-32 text-[13px]"
                />
                <FilterSelect
                    testId="archives-filter-assignee"
                    value={filters.assignee}
                    options={assigneeOptions}
                    allLabel={t('projectArchives.filters.allAssignees')}
                    onChange={(value) => setFilter('assignee', value)}
                    className="h-7.5 w-36 text-[13px]"
                />
                <label className="flex items-center gap-1.5 text-[13px] text-muted-foreground">
                    <span>{t('projectArchives.filters.archivedFrom')}</span>
                    <Input
                        type="date"
                        value={filters.archivedFrom}
                        onChange={(e) => setFilter('archived_from', e.target.value)}
                        data-test="archives-filter-from"
                        className="h-7.5 w-36 text-[13px]"
                    />
                </label>
                <span className="text-muted-foreground">—</span>
                <label className="flex items-center gap-1.5 text-[13px] text-muted-foreground">
                    <span>{t('projectArchives.filters.archivedTo')}</span>
                    <Input
                        type="date"
                        value={filters.archivedTo}
                        onChange={(e) => setFilter('archived_to', e.target.value)}
                        data-test="archives-filter-to"
                        className="h-7.5 w-36 text-[13px]"
                    />
                </label>
                <span className="ml-auto font-mono text-[13px] text-muted-foreground tabular-nums">
                    {pagination.total}
                </span>
            </div>

            {issues.length === 0 ? (
                <p
                    className="rounded-lg border border-dashed px-4 py-10 text-center text-sm text-muted-foreground"
                    data-test="archives-empty"
                >
                    {hasActiveFilters
                        ? t('projectArchives.emptyFiltered')
                        : t('projectArchives.empty')}
                </p>
            ) : (
                <>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('projectArchives.issue')}</TableHead>
                                <TableHead>{t('projectArchives.status')}</TableHead>
                                <TableHead>{t('projectArchives.priority')}</TableHead>
                                <TableHead>{t('projectArchives.assignees')}</TableHead>
                                <TableHead>{t('projectArchives.archivedAt')}</TableHead>
                                {canUnarchive ? <TableHead className="w-0" /> : null}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {issues.map((issue) => (
                                <TableRow key={issue.id}>
                                    <TableCell>
                                        <Link
                                            href={route('issues.show', issue.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {issue.title}
                                        </Link>
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge name={issue.status.name} category={issue.status.category} />
                                    </TableCell>
                                    <TableCell>
                                        <PriorityBadge priority={issue.priority} />
                                    </TableCell>
                                    <TableCell>
                                        <AvatarStack people={issue.assignees} />
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {formatShortDate(issue.archivedAt)}
                                    </TableCell>
                                    {canUnarchive ? (
                                        <TableCell>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                data-test={`archive-restore-${issue.id}`}
                                                onClick={() => unarchive(issue.id)}
                                            >
                                                <ArchiveRestore className="size-3.5" aria-hidden />
                                                {t('projectArchives.unarchive')}
                                            </Button>
                                        </TableCell>
                                    ) : null}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>

                    {pagination.lastPage > 1 && (
                        <div className="mt-4 flex items-center justify-between">
                            <p className="text-sm text-muted-foreground">
                                {t('projectArchives.pagination.showing', {
                                    from: pagination.from,
                                    to: pagination.to,
                                    total: pagination.total,
                                })}
                            </p>
                            <div className="flex items-center gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={pagination.currentPage <= 1}
                                    onClick={() => goToPage(pagination.currentPage - 1)}
                                    data-test="archives-pagination-prev"
                                >
                                    <ChevronLeft aria-hidden />
                                    {t('projectArchives.pagination.previous')}
                                </Button>
                                <span className="text-sm text-muted-foreground">
                                    {t('projectArchives.pagination.page', {
                                        current: pagination.currentPage,
                                        last: pagination.lastPage,
                                    })}
                                </span>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={pagination.currentPage >= pagination.lastPage}
                                    onClick={() => goToPage(pagination.currentPage + 1)}
                                    data-test="archives-pagination-next"
                                >
                                    {t('projectArchives.pagination.next')}
                                    <ChevronRight aria-hidden />
                                </Button>
                            </div>
                        </div>
                    )}
                </>
            )}
        </AppLayout>
    );
}

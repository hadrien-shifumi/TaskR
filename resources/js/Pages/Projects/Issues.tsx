import { FilterBar } from '@/components/filter-bar';
import { FlashBanner } from '@/components/flash-banner';
import { HealthBadge } from '@/components/issue-badges';
import { ProjectMenu } from '@/components/project-menu';
import { ProjectViewSwitcher } from '@/components/project-view-switcher';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { IssueCard } from '@/Pages/Issues/Index/components/issue-card';
import { IssueTableRow } from '@/Pages/Issues/Index/components/issue-row';
import type { IssueRow } from '@/Pages/Issues/Index/types';
import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import { ArrowLeft, ChevronLeft, ChevronRight } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { HealthData, ProjectRef } from '@/types';

type PageProps = {
    project: ProjectRef;
    health: HealthData;
    issues: IssueRow[];
    /** Tickets matching the filters. */
    total: number;
    /** Project total before any filter — the "visible / total" counter. */
    totalUnfiltered: number;
    /** Current list page (1-based). */
    page: number;
    perPage: number;
    filters: Record<string, string>;
};

/** Tickets list of one project: the same paginated engine as the
 *  workspace issues page, scoped to the project, with the shared
 *  title / identifier search. The filters live in the URL. */
export default function Issues() {
    const {
        project,
        health,
        issues,
        total,
        totalUnfiltered,
        page,
        perPage,
        filters,
    } = usePage<PageProps>().props;
    const { t } = useTranslation();
    const [search, setSearch] = useState(filters.q ?? '');

    /** Props that change when the list refetches: the partial reload
     *  keeps the header props behind. */
    const listOnly = ['issues', 'total', 'totalUnfiltered', 'page', 'perPage', 'filters'];

    /** Sets one URL filter; an empty value removes the param so filtered
     *  URLs stay clean and shareable. A search change returns to page 1. */
    const setFilter = (key: string, value: string) => {
        const next: Record<string, string> = { ...filters };
        if (value === '') {
            delete next[key];
        } else {
            next[key] = value;
        }
        delete next.page;
        router.get(route('projects.issues', project.id), next, {
            preserveState: true,
            only: listOnly,
        });
    };

    // Debounced search: the query param follows the input with a beat.
    useEffect(() => {
        const timer = setTimeout(() => {
            if (search !== (filters.q ?? '')) {
                setFilter('q', search);
            }
        }, 300);
        return () => clearTimeout(timer);
    }, [search]);

    /** Navigates to a list page, keeping every active filter. */
    const goToPage = (target: number) => {
        const next: Record<string, string> = { ...filters };
        if (target <= 1) {
            delete next.page;
        } else {
            next.page = String(target);
        }
        router.get(route('projects.issues', project.id), next, {
            preserveState: true,
            only: listOnly,
        });
    };

    const lastPage = Math.max(1, Math.ceil(total / perPage));
    const hasActiveFilters = Object.keys(filters).length > 0;

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
                    <ProjectViewSwitcher projectId={project.id} active="issues" />
                    <ProjectMenu project={project} issuesCount={totalUnfiltered} />
                </>
            }
        >
            <Head title={t('projectIssues.headTitle', { name: project.name })} />

            <FlashBanner />

            <FilterBar
                search={search}
                onSearchChange={setSearch}
                searchPlaceholder={t('projectBoard.searchPlaceholder')}
                searchAriaLabel={t('projectBoard.searchAria')}
                searchClassName="w-full sm:w-50"
                visible={issues.length}
                total={totalUnfiltered}
            />

            {issues.length === 0 ? (
                <div data-test="issues-empty" className="rounded-xl border bg-card px-5 py-10 text-center">
                    <p className="font-medium">
                        {hasActiveFilters ? t('issuesPage.emptyFiltered') : t('issuesPage.empty')}
                    </p>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {hasActiveFilters
                            ? t('issuesPage.emptyFilteredHint')
                            : t('issuesPage.emptyHint')}
                    </p>
                </div>
            ) : (
                <>
                    {/* Mobile: adaptive cards, one per issue. */}
                    <div data-test="issues-card-list" className="grid gap-2.5 md:hidden">
                        {issues.map((issue) => (
                            <IssueCard key={issue.id} issue={issue} />
                        ))}
                    </div>
                    {/* Desktop: the table. */}
                    <div className="hidden overflow-hidden rounded-xl border bg-card md:block">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-[30%]">{t('columns.title')}</TableHead>
                                    <TableHead>{t('columns.status')}</TableHead>
                                    <TableHead>{t('columns.priority')}</TableHead>
                                    <TableHead className="hidden md:table-cell">{t('columns.labels')}</TableHead>
                                    <TableHead>{t('columns.assignees')}</TableHead>
                                    <TableHead>{t('columns.dueDate')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {issues.map((issue) => (
                                    <IssueTableRow key={issue.id} issue={issue} />
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </>
            )}

            {lastPage > 1 ? (
                <nav
                    aria-label={t('issuesPage.pagination.aria')}
                    data-test="issues-pagination"
                    className="mt-4 flex flex-col items-center gap-2.5 sm:flex-row sm:justify-between"
                >
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={page <= 1}
                        onClick={() => goToPage(page - 1)}
                        data-test="issues-pagination-prev"
                        className="w-full justify-center sm:w-auto"
                    >
                        <ChevronLeft aria-hidden />
                        {t('issuesPage.pagination.previous')}
                    </Button>
                    <span className="order-first text-center text-sm text-muted-foreground tabular-nums" data-test="issues-pagination-counter">
                        {t('issuesPage.pagination.page', {
                            current: page,
                            last: lastPage,
                            total,
                        })}
                    </span>
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={page >= lastPage}
                        onClick={() => goToPage(page + 1)}
                        data-test="issues-pagination-next"
                        className="w-full justify-center sm:w-auto"
                    >
                        {t('issuesPage.pagination.next')}
                        <ChevronRight aria-hidden />
                    </Button>
                </nav>
            ) : null}

            <p className="mt-3 text-[13px] text-muted-foreground">
                {t('issuesPage.ulidNote')}
            </p>
        </AppLayout>
    );
}

import { CreateIssueDialog } from '@/components/create-issue-dialog';
import { FilterBar } from '@/components/filter-bar';
import { FilterMultiSelect } from '@/components/filter-multi-select';
import { FilterSelect } from '@/components/filter-select';
import { FlashBanner } from '@/components/flash-banner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { LabelRef, UserRef } from '@/types';
import { CreateLabelDialog } from './Index/components/create-label-dialog';
import { IssuesViewSwitcher } from './Index/components/issues-view-switcher';
import { IssueTableRow } from './Index/components/issue-row';
import { ProjectKanbanBoard } from './Index/components/project-kanban-board';
import { SortableHead } from './Index/components/sortable-head';
import type { SortKey } from './Index/components/sortable-head';
import type { IssueRow, ProjectBoard, TemplateOption } from './Index/types';

type PageProps = {
    issues?: IssueRow[];
    total?: number;
    totalUnfiltered: number;
    view: 'list' | 'kanban';
    /** Current list page (list view only, 1-based). */
    page?: number;
    /** Tickets per page (list view only). */
    perPage?: number;
    projectBoards?: ProjectBoard[];
    /** Active URL filters; `label` is an array when several labels are selected. */
    filters: Record<string, string | string[]>;
    projects: { id: string; name: string }[];
    statuses: { id: string; name: string; category: string }[];
    labels: LabelRef[];
    members: UserRef[];
    templates: TemplateOption[];
};

/** All status categories offered by the category filter select. */
const CATEGORY_OPTIONS = ['backlog', 'in_progress', 'done', 'cancelled'] as const;

/** All priorities offered by the priority filter select. */
const PRIORITY_OPTIONS = ['none', 'low', 'high', 'urgent'] as const;

/** Sort keys accepted by the list (mirrors ListIssues::SORT_KEYS). */
const SORT_KEYS: SortKey[] = ['created', 'priority', 'due_date', 'status', 'project'];

/** Reads a scalar URL filter; array filters (label) read as '' here. */
const readFilter = (filters: Record<string, string | string[]>, key: string): string => {
    const value = filters[key];
    return typeof value === 'string' ? value : '';
};

/** Reads a multi-valued URL filter (label); a scalar param reads as one value. */
const readMultiFilter = (filters: Record<string, string | string[]>, key: string): string[] => {
    const value = filters[key];
    if (Array.isArray(value)) {
        return value;
    }
    return typeof value === 'string' && value !== '' ? [value] : [];
};

export default function Index() {
    const {
        issues,
        total,
        totalUnfiltered,
        view,
        page,
        perPage,
        projectBoards,
        filters,
        projects,
        statuses,
        labels,
        members,
        templates,
    } = usePage<PageProps>().props;
    const { t } = useTranslation();
    const [creating, setCreating] = useState(false);
    const [creatingLabel, setCreatingLabel] = useState(false);
    const [search, setSearch] = useState(readFilter(filters, 'q'));

    /** Sets one URL filter; an empty value removes the param so filtered
     *  URLs stay clean and shareable. Array values serialize as `key[]`. */
    const setFilter = (key: string, value: string | string[]) => {
        const next: Record<string, string | string[]> = { ...filters };
        if (value === '' || (Array.isArray(value) && value.length === 0)) {
            delete next[key];
        } else {
            next[key] = value;
        }
        router.get(route('issues.index'), next, { preserveState: true });
    };

    /** Current sort key — `created` (newest first) is the default order. */
    const activeSort: SortKey = SORT_KEYS.find((key) => key === readFilter(filters, 'sort')) ?? 'created';

    /** Current direction: desc for the default created order, asc otherwise. */
    const requestedDir = readFilter(filters, 'dir');
    const activeDir: 'asc' | 'desc' =
        requestedDir === 'asc' || requestedDir === 'desc'
            ? requestedDir
            : activeSort === 'created'
              ? 'desc'
              : 'asc';

    /** Sorts by a column and returns to page 1. Clicking the default
     *  created order again clears the params so the URL stays clean. */
    const sortBy = (sort: SortKey, dir: 'asc' | 'desc') => {
        const next: Record<string, string | string[]> = { ...filters };
        delete next.page;
        if (sort === 'created' && dir === 'desc') {
            delete next.sort;
            delete next.dir;
        } else {
            next.sort = sort;
            next.dir = dir;
        }
        router.get(route('issues.index'), next, { preserveState: true });
    };

    /** Navigates to a list page, keeping every active filter and the sort. */
    const goToPage = (target: number) => {
        const next: Record<string, string | string[]> = { ...filters };
        if (target <= 1) {
            delete next.page;
        } else {
            next.page = String(target);
        }
        router.get(route('issues.index'), next, { preserveState: true });
    };

    // Debounced title search: the query param follows the input with a beat.
    useEffect(() => {
        const timer = setTimeout(() => {
            if (search !== readFilter(filters, 'q')) {
                setFilter('q', search);
            }
        }, 300);
        return () => clearTimeout(timer);
    }, [search]);

    const hasActiveFilters = Object.keys(filters).length > 0;

    /** Number of issues currently visible — summed from boards in kanban view. */
    const visibleCount =
        view === 'kanban'
            ? (projectBoards ?? []).reduce(
                  (sum, board) =>
                      sum + board.columns.reduce((colSum, col) => colSum + col.issuesCount, 0),
                  0,
              )
            : (issues?.length ?? 0);

    const currentPage = page ?? 1;
    const pageSize = perPage ?? 50;
    const filteredTotal = total ?? visibleCount;
    const lastPage = Math.max(1, Math.ceil(filteredTotal / pageSize));

    const categoryOptions = CATEGORY_OPTIONS.map((category) => ({
        value: category,
        label: t(`statusCategory.${category}`),
    }));

    const priorityOptions = PRIORITY_OPTIONS.map((priority) => ({
        value: priority,
        label: t(`priority.${priority}`),
    }));

    const assigneeOptions = members.map((member) => ({
        value: member.id,
        label: member.name,
    }));

    const projectOptions = projects.map((project) => ({
        value: project.id,
        label: project.name,
    }));

    const statusOptions = statuses.map((status) => ({
        value: status.id,
        label: status.name,
    }));

    const labelOptions = labels.map((label) => ({
        value: label.id,
        label: label.name,
    }));

    const overdueOptions = [{ value: '1', label: t('issuesPage.filters.overdue') }];

    const blockedOptions = [{ value: '1', label: t('issuesPage.filters.blocked') }];

    return (
        <AppLayout
            title={t('issuesPage.title')}
            subtitle={t('issuesPage.subtitle')}
            actions={
                <>
                    <IssuesViewSwitcher active={view} filters={filters} />
                    <Button variant="outline" size="sm" onClick={() => setCreatingLabel(true)}>
                        {t('issuesPage.newLabel')}
                    </Button>
                    <Button size="sm" onClick={() => setCreating(true)}>
                        {t('issues.newIssue')}
                    </Button>
                </>
            }
        >
            <Head title={t('issuesPage.title')} />

            <FlashBanner />

            <FilterBar
                search={search}
                onSearchChange={setSearch}
                searchPlaceholder={t('issuesPage.searchPlaceholder')}
                searchAriaLabel={t('issuesPage.searchAria')}
                visible={visibleCount}
                total={totalUnfiltered}
            >
                <FilterSelect
                    testId="filter-select-category"
                    value={readFilter(filters, 'category')}
                    options={categoryOptions}
                    allLabel={t('issuesPage.filters.allStatuses')}
                    onChange={(value) => setFilter('category', value)}
                    className="h-7.5 w-36 text-[13px]"
                />
                <FilterSelect
                    testId="filter-select-status"
                    value={readFilter(filters, 'status')}
                    options={statusOptions}
                    allLabel={t('issuesPage.filters.status')}
                    ariaLabel={t('issuesPage.filters.status')}
                    onChange={(value) => setFilter('status', value)}
                    className="h-7.5 w-36 text-[13px]"
                />
                <FilterMultiSelect
                    testId="filter-labels"
                    values={readMultiFilter(filters, 'label')}
                    options={labelOptions}
                    allLabel={t('issuesPage.filters.allLabels')}
                    ariaLabel={t('issuesPage.filters.labels')}
                    onChange={(values) => setFilter('label', values)}
                    className="h-7.5 w-36 text-[13px]"
                />
                <label className="flex items-center gap-1.5 text-[13px] text-muted-foreground">
                    <span>{t('issuesPage.filters.dueAfter')}</span>
                    <Input
                        type="date"
                        value={readFilter(filters, 'due_after')}
                        onChange={(e) => setFilter('due_after', e.target.value)}
                        aria-label={t('issuesPage.filters.dueAfter')}
                        data-test="filter-due-after"
                        className="h-7.5 w-36 text-[13px]"
                    />
                </label>
                <label className="flex items-center gap-1.5 text-[13px] text-muted-foreground">
                    <span>{t('issuesPage.filters.dueBefore')}</span>
                    <Input
                        type="date"
                        value={readFilter(filters, 'due_before')}
                        onChange={(e) => setFilter('due_before', e.target.value)}
                        aria-label={t('issuesPage.filters.dueBefore')}
                        data-test="filter-due-before"
                        className="h-7.5 w-36 text-[13px]"
                    />
                </label>
                <FilterSelect
                    testId="filter-select-priority"
                    value={readFilter(filters, 'priority')}
                    options={priorityOptions}
                    allLabel={t('issuesPage.filters.allPriorities')}
                    onChange={(value) => setFilter('priority', value)}
                    className="h-7.5 w-32 text-[13px]"
                />
                <FilterSelect
                    testId="filter-select-assignee"
                    value={readFilter(filters, 'assignee')}
                    options={assigneeOptions}
                    allLabel={t('issuesPage.filters.allAssignees')}
                    onChange={(value) => setFilter('assignee', value)}
                    className="h-7.5 w-36 text-[13px]"
                />
                <FilterSelect
                    testId="filter-select-project"
                    value={readFilter(filters, 'project')}
                    options={projectOptions}
                    allLabel={t('issuesPage.filters.allProjects')}
                    onChange={(value) => setFilter('project', value)}
                    className="h-7.5 w-36 text-[13px]"
                />
                <FilterSelect
                    testId="filter-select-overdue"
                    value={readFilter(filters, 'overdue')}
                    options={overdueOptions}
                    allLabel={t('issuesPage.filters.allIssues')}
                    onChange={(value) => setFilter('overdue', value)}
                    className="h-7.5 w-32 text-[13px]"
                />
                <FilterSelect
                    testId="filter-select-blocked"
                    value={readFilter(filters, 'blocked')}
                    options={blockedOptions}
                    allLabel={t('issuesPage.filters.allIssues')}
                    onChange={(value) => setFilter('blocked', value)}
                    className="h-7.5 w-32 text-[13px]"
                />
            </FilterBar>

            {view === 'kanban' ? (
                <>
                    {(projectBoards ?? []).map((board) => (
                        <ProjectKanbanBoard key={board.project.id} board={board} />
                    ))}
                    {visibleCount === 0 ? (
                        <div data-test="issues-empty" className="px-5 py-10 text-center">
                            <p className="font-medium">
                                {hasActiveFilters ? t('issuesPage.emptyFiltered') : t('issuesPage.empty')}
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {hasActiveFilters
                                    ? t('issuesPage.emptyFilteredHint')
                                    : t('issuesPage.emptyHint')}
                            </p>
                        </div>
                    ) : null}
                </>
            ) : (
                <>
                    <div className="overflow-hidden rounded-xl border bg-card">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <SortableHead
                                        sortKey="project"
                                        label={t('columns.title')}
                                        activeSort={activeSort}
                                        activeDir={activeDir}
                                        onSort={sortBy}
                                        className="w-[30%]"
                                    />
                                    <SortableHead
                                        sortKey="status"
                                        label={t('columns.status')}
                                        activeSort={activeSort}
                                        activeDir={activeDir}
                                        onSort={sortBy}
                                    />
                                    <SortableHead
                                        sortKey="priority"
                                        label={t('columns.priority')}
                                        activeSort={activeSort}
                                        activeDir={activeDir}
                                        onSort={sortBy}
                                    />
                                    <TableHead className="hidden md:table-cell">{t('columns.labels')}</TableHead>
                                    <TableHead>{t('columns.assignees')}</TableHead>
                                    <SortableHead
                                        sortKey="due_date"
                                        label={t('columns.dueDate')}
                                        activeSort={activeSort}
                                        activeDir={activeDir}
                                        onSort={sortBy}
                                    />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {(issues ?? []).map((issue) => (
                                    <IssueTableRow key={issue.id} issue={issue} />
                                ))}
                            </TableBody>
                        </Table>
                        {visibleCount === 0 ? (
                            <div data-test="issues-empty" className="px-5 py-10 text-center">
                                <p className="font-medium">
                                    {hasActiveFilters ? t('issuesPage.emptyFiltered') : t('issuesPage.empty')}
                                </p>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {hasActiveFilters
                                        ? t('issuesPage.emptyFilteredHint')
                                        : t('issuesPage.emptyHint')}
                                </p>
                            </div>
                        ) : null}
                    </div>

                    {lastPage > 1 ? (
                        <nav
                            aria-label={t('issuesPage.pagination.aria')}
                            data-test="issues-pagination"
                            className="mt-4 flex items-center justify-between"
                        >
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={currentPage <= 1}
                                onClick={() => goToPage(currentPage - 1)}
                                data-test="issues-pagination-prev"
                            >
                                <ChevronLeft aria-hidden />
                                {t('issuesPage.pagination.previous')}
                            </Button>
                            <span className="text-sm text-muted-foreground tabular-nums" data-test="issues-pagination-counter">
                                {t('issuesPage.pagination.page', {
                                    current: currentPage,
                                    last: lastPage,
                                    total: filteredTotal,
                                })}
                            </span>
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={currentPage >= lastPage}
                                onClick={() => goToPage(currentPage + 1)}
                                data-test="issues-pagination-next"
                            >
                                {t('issuesPage.pagination.next')}
                                <ChevronRight aria-hidden />
                            </Button>
                        </nav>
                    ) : null}
                </>
            )}

            <p className="mt-3 text-[13px] text-muted-foreground">
                {t('issuesPage.ulidNote')}
            </p>

            <CreateIssueDialog
                open={creating}
                onClose={() => setCreating(false)}
                projects={projects}
                statuses={statuses}
                labels={labels}
                templates={templates}
            />

            <CreateLabelDialog open={creatingLabel} onClose={() => setCreatingLabel(false)} />
        </AppLayout>
    );
}

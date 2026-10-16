import { CreateIssueDialog } from '@/components/create-issue-dialog';
import { FilterBar } from '@/components/filter-bar';
import { FilterSelect } from '@/components/filter-select';
import { FlashBanner } from '@/components/flash-banner';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { LabelRef, UserRef } from '@/types';
import { CreateLabelDialog } from './Index/components/create-label-dialog';
import { IssuesViewSwitcher } from './Index/components/issues-view-switcher';
import { IssueTableRow } from './Index/components/issue-row';
import { ProjectKanbanBoard } from './Index/components/project-kanban-board';
import type { IssueRow, ProjectBoard, TemplateOption } from './Index/types';

type PageProps = {
    issues?: IssueRow[];
    total?: number;
    totalUnfiltered: number;
    view: 'list' | 'kanban';
    projectBoards?: ProjectBoard[];
    filters: Record<string, string>;
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

export default function Index() {
    const { issues, totalUnfiltered, view, projectBoards, filters, projects, statuses, labels, members, templates } =
        usePage<PageProps>().props;
    const { t } = useTranslation();
    const [creating, setCreating] = useState(false);
    const [creatingLabel, setCreatingLabel] = useState(false);
    const [search, setSearch] = useState(filters.q ?? '');

    /** Sets one URL filter; an empty value removes the param so filtered
     *  URLs stay clean and shareable. */
    const setFilter = (key: string, value: string) => {
        const next: Record<string, string> = { ...filters };
        if (value === '') {
            delete next[key];
        } else {
            next[key] = value;
        }
        router.get(route('issues.index'), next, { preserveState: true });
    };

    // Debounced title search: the query param follows the input with a beat.
    useEffect(() => {
        const timer = setTimeout(() => {
            if (search !== (filters.q ?? '')) {
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

    const overdueOptions = [{ value: '1', label: t('issuesPage.filters.overdue') }];

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
                    value={filters.category ?? ''}
                    options={categoryOptions}
                    allLabel={t('issuesPage.filters.allStatuses')}
                    onChange={(value) => setFilter('category', value)}
                    className="h-7.5 w-36 text-[13px]"
                />
                <FilterSelect
                    testId="filter-select-priority"
                    value={filters.priority ?? ''}
                    options={priorityOptions}
                    allLabel={t('issuesPage.filters.allPriorities')}
                    onChange={(value) => setFilter('priority', value)}
                    className="h-7.5 w-32 text-[13px]"
                />
                <FilterSelect
                    testId="filter-select-assignee"
                    value={filters.assignee ?? ''}
                    options={assigneeOptions}
                    allLabel={t('issuesPage.filters.allAssignees')}
                    onChange={(value) => setFilter('assignee', value)}
                    className="h-7.5 w-36 text-[13px]"
                />
                <FilterSelect
                    testId="filter-select-project"
                    value={filters.project ?? ''}
                    options={projectOptions}
                    allLabel={t('issuesPage.filters.allProjects')}
                    onChange={(value) => setFilter('project', value)}
                    className="h-7.5 w-36 text-[13px]"
                />
                <FilterSelect
                    testId="filter-select-overdue"
                    value={filters.overdue ?? ''}
                    options={overdueOptions}
                    allLabel={t('issuesPage.filters.allIssues')}
                    onChange={(value) => setFilter('overdue', value)}
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
                <div className="overflow-hidden rounded-xl border bg-card">
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

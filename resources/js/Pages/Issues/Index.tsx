import { CreateIssueDialog } from '@/components/create-issue-dialog';
import { FilterBar } from '@/components/filter-bar';
import { FilterChip } from '@/components/filter-chip';
import { FlashBanner } from '@/components/flash-banner';
import { Avatar } from '@/components/issue-badges';
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
import { Clock, ListTodo } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { LabelRef, PageSharedProps, UserRef } from '@/types';
import { CreateLabelDialog } from './Index/components/create-label-dialog';
import { IssueTableRow } from './Index/components/issue-row';
import type { IssueRow, TemplateOption } from './Index/types';

type PageProps = {
    issues: IssueRow[];
    total: number;
    totalUnfiltered: number;
    filters: Record<string, string>;
    projects: { id: string; name: string }[];
    statuses: { id: string; name: string; category: string }[];
    labels: LabelRef[];
    members: UserRef[];
    templates: TemplateOption[];
};

export default function Index() {
    const { issues, total, totalUnfiltered, filters, projects, statuses, labels, templates } =
        usePage<PageProps>().props;
    const { auth } = usePage<PageSharedProps>().props;
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

    /** Chip toggle: the filter is active when the URL param equals the chip value. */
    const toggleFilter = (key: string, value: string) => () => {
        setFilter(key, filters[key] === value ? '' : value);
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

    const myId = auth.user ? String(auth.user.id) : '';
    const hasActiveFilters = Object.keys(filters).length > 0;

    return (
        <AppLayout
            title={t('issuesPage.title')}
            subtitle={t('issuesPage.subtitle')}
            actions={
                <>
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
                visible={issues.length}
                total={totalUnfiltered}
            >
                <FilterChip
                    testId="filter-chip-in-progress"
                    active={filters.category === 'in_progress'}
                    onToggle={toggleFilter('category', 'in_progress')}
                >
                    <ListTodo aria-hidden className="size-3.25" />
                    {t('statusCategory.in_progress')}
                </FilterChip>
                <FilterChip
                    testId="filter-chip-done"
                    active={filters.category === 'done'}
                    onToggle={toggleFilter('category', 'done')}
                >
                    {t('statusCategory.done')}
                </FilterChip>
                <FilterChip
                    testId="filter-chip-overdue"
                    active={filters.overdue === '1'}
                    onToggle={toggleFilter('overdue', '1')}
                >
                    <Clock aria-hidden className="size-3.25" />
                    {t('issuesPage.chips.overdue')}
                </FilterChip>
                <FilterChip
                    testId="filter-chip-urgent"
                    active={filters.priority === 'urgent'}
                    onToggle={toggleFilter('priority', 'urgent')}
                >
                    {t('issuesPage.chips.urgent')}
                </FilterChip>
                {auth.user ? (
                    <FilterChip
                        testId="filter-chip-mine"
                        active={filters.assignee === myId}
                        onToggle={toggleFilter('assignee', myId)}
                    >
                        <Avatar name={auth.user.name} />
                        {t('issuesPage.chips.mine')}
                    </FilterChip>
                ) : null}
                {projects.map((project) => (
                    <FilterChip
                        key={project.id}
                        testId={`filter-chip-project-${project.id}`}
                        active={filters.project === project.id}
                        onToggle={toggleFilter('project', project.id)}
                    >
                        {t('issuesPage.chips.project', { name: project.name })}
                    </FilterChip>
                ))}
            </FilterBar>

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
                        {issues.map((issue) => (
                            <IssueTableRow key={issue.id} issue={issue} />
                        ))}
                    </TableBody>
                </Table>
                {issues.length === 0 ? (
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

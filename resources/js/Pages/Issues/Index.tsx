import { CreateIssueDialog } from '@/components/create-issue-dialog';
import { FlashBanner } from '@/components/flash-banner';
import { FilterSelect } from '@/components/filter-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { PRIORITIES } from '@/components/priority-select';
import type { LabelRef, UserRef } from '@/types';
import { CreateLabelDialog } from './Index/components/create-label-dialog';
import { IssueTableRow } from './Index/components/issue-row';
import type { IssueRow, TemplateOption } from './Index/types';

type PageProps = {
    issues: IssueRow[];
    total: number;
    filters: Record<string, string>;
    projects: { id: string; name: string }[];
    statuses: { id: string; name: string; category: string }[];
    labels: LabelRef[];
    members: UserRef[];
    templates: TemplateOption[];
};

const CATEGORIES = ['backlog', 'in_progress', 'done', 'cancelled'];

export default function Index() {
    const { issues, total, filters, projects, statuses, labels, members, templates } = usePage<PageProps>().props;
    const [creating, setCreating] = useState(false);
    const [creatingLabel, setCreatingLabel] = useState(false);
    const [search, setSearch] = useState(filters.q ?? '');

    const setFilter = (key: string, value: string) => {
        router.get(route('issues.index'), { ...filters, [key]: value }, { preserveState: true });
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

    return (
        <AppLayout
            title="Issues"
            subtitle="All projects of the workspace"
            actions={
                <>
                    <Button variant="outline" size="sm" onClick={() => setCreatingLabel(true)}>
                        New label
                    </Button>
                    <Button size="sm" onClick={() => setCreating(true)}>
                        New issue
                    </Button>
                </>
            }
        >
            <Head title="Issues" />

            <FlashBanner />

            <div className="mb-3.5 flex flex-wrap items-center gap-2">
                <div className="relative">
                    <Search
                        className="pointer-events-none absolute top-2.5 left-2.5 size-3.5 text-muted-foreground"
                        aria-hidden
                    />
                    <Input
                        type="search"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Search a title…"
                        aria-label="Search issues by title"
                        className="h-7.5 w-50 pl-8 text-[13px]"
                    />
                </div>
                <FilterSelect
                    value={filters.project ?? ''}
                    options={projects.map((p) => ({ value: p.id, label: p.name }))}
                    allLabel="All projects"
                    onChange={(v) => setFilter('project', v)}
                />
                <FilterSelect
                    value={filters.status ?? ''}
                    options={statuses.map((s) => ({ value: s.id, label: s.name }))}
                    allLabel="All statuses"
                    onChange={(v) => setFilter('status', v)}
                />
                <FilterSelect
                    value={filters.category ?? ''}
                    options={CATEGORIES.map((c) => ({ value: c, label: c }))}
                    allLabel="All categories"
                    onChange={(v) => setFilter('category', v)}
                />
                <FilterSelect
                    className="w-36"
                    value={filters.priority ?? ''}
                    options={PRIORITIES.map((p) => ({ value: p, label: p }))}
                    allLabel="Any priority"
                    onChange={(v) => setFilter('priority', v)}
                />
                <FilterSelect
                    className="w-36"
                    value={filters.label ?? ''}
                    options={labels.map((l) => ({ value: l.id, label: l.name }))}
                    allLabel="Any label"
                    onChange={(v) => setFilter('label', v)}
                />
                <FilterSelect
                    className="w-40"
                    value={filters.assignee ?? ''}
                    options={members.map((m) => ({ value: m.id, label: m.name }))}
                    allLabel="Any assignee"
                    onChange={(v) => setFilter('assignee', v)}
                />
                <Button
                    variant={filters.overdue === '1' ? 'default' : 'outline'}
                    size="sm"
                    onClick={() => setFilter('overdue', filters.overdue === '1' ? '' : '1')}
                >
                    Overdue
                </Button>
                <span className="ml-auto font-mono text-xs text-muted-foreground tabular-nums">
                    {total} {total === 1 ? 'issue' : 'issues'}
                </span>
            </div>

            <div className="overflow-hidden rounded-xl border bg-card">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="w-[30%]">Title</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Priority</TableHead>
                            <TableHead className="hidden md:table-cell">Labels</TableHead>
                            <TableHead>Assignees</TableHead>
                            <TableHead>Due date</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {issues.map((issue) => (
                            <IssueTableRow key={issue.id} issue={issue} />
                        ))}
                        {issues.length === 0 ? (
                            <TableRow>
                                <TableCell colSpan={6} className="py-6 text-center text-muted-foreground">
                                    No issues match these filters.
                                </TableCell>
                            </TableRow>
                        ) : null}
                    </TableBody>
                </Table>
            </div>

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

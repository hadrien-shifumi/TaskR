import { ProjectMenu } from '@/components/project-menu';
import { ProjectViewSwitcher } from '@/components/project-view-switcher';
import { FilterSelect } from '@/components/filter-select';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { ActivitySection } from '@/Pages/Issues/Show/components/activity-section';
import type { ActivityEvent } from '@/Pages/Issues/Show/types';
import { ArrowLeft, ChevronLeft, ChevronRight } from 'lucide-react';
import { Head, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

type PageProps = {
    project: {
        id: string;
        name: string;
        description: string | null;
        targetDate: string | null;
    };
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
    actors: { id: number; name: string }[];
    types: string[];
    transitions: { from: string; to: string; count: number }[];
};

/** Reads a scalar query param from the current Inertia URL. */
const readParam = (url: string, key: string): string => {
    const query = url.split('?')[1] ?? '';
    return new URLSearchParams(query).get(key) ?? '';
};

export default function Activity() {
    const { project, issuesCount, events, pagination, actors, types, transitions } = usePage<PageProps>().props;
    const { url } = usePage();
    const { t } = useTranslation();

    const actorFilter = readParam(url, 'actor_id');
    const typeFilter = readParam(url, 'type');

    const actorOptions = actors.map((actor) => ({ value: String(actor.id), label: actor.name }));
    const typeOptions = types.map((type) => ({ value: type, label: t(`audit.types.${type}`) }));

    /** Pushes both filters as the full query string; empty values drop their
     *  param so filtered URLs stay clean and shareable. The page param is
     *  never carried over: changing a filter lands back on page 1. */
    const setFilter = (key: 'actor_id' | 'type', value: string) => {
        const next: Record<string, string> = {};
        const nextActor = key === 'actor_id' ? value : actorFilter;
        const nextType = key === 'type' ? value : typeFilter;
        if (nextActor) {
            next.actor_id = nextActor;
        }
        if (nextType) {
            next.type = nextType;
        }
        router.get(route('projects.activity', project.id), next, {
            preserveState: true,
            // Filters are read from the URL client-side: only the journal
            // and its pagination refetch.
            only: ['events', 'pagination'],
        });
    };

    /** Navigates to a page, keeping the active filters. */
    const goToPage = (page: number) => {
        const params: Record<string, string> = { page: String(page) };
        if (actorFilter) params.actor_id = actorFilter;
        if (typeFilter) params.type = typeFilter;
        router.get(route('projects.activity', project.id), params, {
            preserveState: true,
            only: ['events', 'pagination'],
        });
    };

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
            title={<span>{project.name}</span>}
            actions={
                <>
                    <ProjectViewSwitcher projectId={project.id} active="activity" />
                    <ProjectMenu project={project} issuesCount={issuesCount} />
                </>
            }
        >
            <Head title={t('audit.headTitle', { name: project.name })} />

            <p className="mb-5 max-w-[62ch] text-[15px] text-muted-foreground">{t('audit.intro')}</p>

            <div className="mb-5 flex flex-wrap items-center gap-2" data-test="activity-filters">
                <FilterSelect
                    testId="filter-select-actor"
                    value={actorFilter}
                    options={actorOptions}
                    allLabel={t('audit.filters.allActors')}
                    ariaLabel={t('audit.filters.actor')}
                    onChange={(value) => setFilter('actor_id', value)}
                    className="h-7.5 w-44 text-[13px]"
                />
                <FilterSelect
                    testId="filter-select-type"
                    value={typeFilter}
                    options={typeOptions}
                    allLabel={t('audit.filters.allTypes')}
                    ariaLabel={t('audit.filters.type')}
                    onChange={(value) => setFilter('type', value)}
                    className="h-7.5 w-48 text-[13px]"
                />
            </div>

            <div className="grid items-start gap-6 lg:grid-cols-[1fr_320px]">
                <div className="min-w-0">
                    <Card className="py-5" data-test="activity-events">
                        <CardContent className="px-5">
                            <ActivitySection events={events} />
                        </CardContent>
                    </Card>

                    {pagination.lastPage > 1 && (
                        <div
                            className="mt-4 flex items-center justify-end gap-2"
                            data-test="activity-pagination"
                        >
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
                            <span
                                className="text-sm text-muted-foreground"
                                data-test="activity-pagination-counter"
                            >
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
                                {t('audit.pagination.next')}
                                <ChevronRight aria-hidden />
                            </Button>
                        </div>
                    )}
                </div>

                <Card className="py-5" data-test="transitions-card">
                    <CardContent className="grid gap-3.5 px-5">
                        <h2 className="text-base font-semibold">{t('audit.transitions')}</h2>
                        <p className="text-xs text-muted-foreground">{t('audit.transitionsHint')}</p>
                        {transitions.length === 0 ? (
                            <p className="text-sm text-muted-foreground">{t('audit.emptyTransitions')}</p>
                        ) : (
                            <Table data-test="transitions-table">
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>{t('audit.transition')}</TableHead>
                                        <TableHead className="text-right">{t('audit.count')}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {transitions.map((transition) => (
                                        <TableRow key={`${transition.from}-${transition.to}`}>
                                            <TableCell className="font-medium">
                                                {t('audit.fromTo', { from: transition.from, to: transition.to })}
                                            </TableCell>
                                            <TableCell className="text-right font-mono tabular-nums">
                                                {transition.count}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}

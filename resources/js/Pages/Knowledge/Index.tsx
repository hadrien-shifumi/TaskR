import AppLayout from '@/layouts/app-layout';
import { Banner } from '@/components/banner';
import { FilterBar } from '@/components/filter-bar';
import { FlashBanner } from '@/components/flash-banner';
import { FormDialog } from '@/components/form-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { MarkdownEditor, MarkdownView } from '@/components/ui/markdown-editor';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    BookOpen,
    ChevronRight,
    Clock,
    Folder,
    Globe,
    History,
    Pencil,
    Plus,
    Search,
    Trash2,
    TriangleAlert,
    User,
    X,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { PageSharedProps } from '@/types';
import { formatDate, formatRelativeDate } from '@/lib/format-date';
import { KnowledgeSidebar, type PageRef, type ProjectOption } from './components/knowledge-sidebar';

interface SelectedPage {
    id: string;
    title: string;
    body: string;
    parentId: string | null;
    projectId: string | null;
    parentChain: { id: string; title: string }[];
    lastEditor: string | null;
    updatedAt: string | null;
}

interface SearchResult {
    id: string;
    title: string;
    excerpt: string;
    score: number;
    projectId: string | null;
}

interface ConflictInfo {
    lastEditor: string | null;
    lastEditorId: number;
    updatedAt: string;
}

type PageProps = {
    pages: PageRef[];
    projects: ProjectOption[];
    selected: SelectedPage | null;
    projectFilter?: string | null;
    query?: string;
    results?: SearchResult[];
    searchUnavailable?: boolean;
};

const NO_PROJECT = '__none__';
const NO_PARENT = '__none__';

const emptyForm = { title: '', body: '', project_id: '', parent_id: '' };

export default function KnowledgeIndex() {
    const { pages, projects, selected, projectFilter, query, results, searchUnavailable } = usePage<PageProps>().props;
    const { workspace } = usePage<PageSharedProps>().props;
    const { t } = useTranslation();

    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [conflict, setConflict] = useState<ConflictInfo | null>(null);
    const [baseUpdatedAt, setBaseUpdatedAt] = useState<string | null>(null);
    const [search, setSearch] = useState(query ?? '');

    const createForm = useForm({ ...emptyForm });
    const editForm = useForm({ ...emptyForm });

    const selectedId = selected?.id ?? null;
    const projectName = (projectId: string | null): string | null =>
        projectId ? (projects.find((project) => project.id === projectId)?.name ?? null) : null;

    /** Opens the create dialog, optionally pre-filling title/project from the empty-state CTAs. */
    const openCreating = (init?: { title?: string; projectId?: string }) => {
        if (init) {
            createForm.setData({
                ...emptyForm,
                title: init.title ?? '',
                project_id: init.projectId ?? '',
            });
        }
        setCreating(true);
    };

    /** Sets the sidebar project filter; an empty value removes the param. */
    const setProjectFilter = (value: string) => {
        const params: Record<string, string> = {};
        if (value) {
            params.project = value;
        }
        router.get(route('knowledge.index'), params, { preserveState: true, preserveScroll: true });
    };

    // Debounced semantic search: fires 400 ms after the last keystroke, with
    // the active sidebar project filter riding along (`project_id` is the
    // param SearchKnowledge validates). Emptying the box returns to the
    // project-filtered index. Partial reloads touch only the search props, so
    // the sidebar tree and filter survive the round trip untouched.
    useEffect(() => {
        const timer = setTimeout(() => {
            if (search === (query ?? '')) {
                return;
            }
            if (search.trim() === '') {
                router.get(
                    route('knowledge.index'),
                    projectFilter ? { project: projectFilter } : {},
                    {
                        preserveState: true,
                        preserveScroll: true,
                        only: ['query', 'results', 'searchUnavailable'],
                    },
                );
                return;
            }
            router.get(
                route('knowledge.search'),
                { q: search, ...(projectFilter ? { project_id: projectFilter } : {}) },
                {
                    preserveState: true,
                    preserveScroll: true,
                    only: ['query', 'results', 'searchUnavailable'],
                },
            );
        }, 400);
        return () => clearTimeout(timer);
    }, [search]);

    // Navigation resync: the URL query prop is the source of truth for the box.
    useEffect(() => {
        setSearch(query ?? '');
    }, [query]);

    const submitCreate = (e: React.FormEvent) => {
        e.preventDefault();
        createForm.post(route('knowledge.store'), {
            onSuccess: () => {
                setCreating(false);
                createForm.reset();
            },
        });
    };

    const submitEdit = (forceOverwrite = false) => {
        if (!selected) return;

        editForm.transform((data) => ({
            title: data.title,
            body: data.body,
            project_id: data.project_id || null,
            parent_id: data.parent_id || null,
            base_updated_at: baseUpdatedAt,
            force_overwrite: forceOverwrite,
        }));

        editForm.patch(route('knowledge.update', selected.id), {
            onSuccess: () => {
                setEditing(false);
                setConflict(null);
                setBaseUpdatedAt(null);
            },
            onError: (errors) => {
                // 409 conflict: Inertia passes the JSON body to onError.
                const conflictData = (errors as Record<string, unknown>)?.conflict as
                    | ConflictInfo
                    | undefined;
                if (conflictData) {
                    setConflict(conflictData);
                }
                // 422 validation errors are set automatically in editForm.errors.
            },
        });
    };

    const remove = () => {
        if (!selected) return;
        router.delete(route('knowledge.destroy', selected.id), {
            onSuccess: () => setDeleting(false),
        });
    };

    const parentOptions = (excludeId?: string): PageRef[] =>
        pages.filter((page) => page.id !== excludeId);

    const projectSelect = (
        form: typeof createForm,
        idPrefix: string,
    ) => (
        <Select
            value={form.data.project_id || NO_PROJECT}
            onValueChange={(v) => {
                if (v !== null) form.setData('project_id', v === NO_PROJECT ? '' : v);
            }}
        >
            <SelectTrigger id={`${idPrefix}-project`} className="w-full">
                <SelectValue>{form.data.project_id ? (projectName(form.data.project_id) ?? t('issues.fields.project')) : t('knowledge.crossWorkspace')}</SelectValue>
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={NO_PROJECT}>{t('knowledge.crossWorkspace')}</SelectItem>
                {projects.map((project) => (
                    <SelectItem key={project.id} value={project.id}>
                        {project.name}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );

    const parentSelect = (
        form: typeof createForm,
        idPrefix: string,
        excludeId?: string,
    ) => (
        <Select
            value={form.data.parent_id || NO_PARENT}
            onValueChange={(v) => {
                if (v !== null) form.setData('parent_id', v === NO_PARENT ? '' : v);
            }}
        >
            <SelectTrigger id={`${idPrefix}-parent`} className="w-full">
                <SelectValue>{form.data.parent_id ? (pages.find((page) => page.id === form.data.parent_id)?.title ?? t('knowledge.parentFallback')) : t('issueShow.noParent')}</SelectValue>
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={NO_PARENT}>{t('issueShow.noParent')}</SelectItem>
                {parentOptions(excludeId).map((page) => (
                    <SelectItem key={page.id} value={page.id}>
                        {page.title}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );

    return (
        <AppLayout
            title={t('knowledge.title')}
            subtitle={workspace?.name}
            actions={
                <Button size="sm" onClick={() => setCreating(true)}>
                    <Plus />
                    {t('knowledge.newPage')}
                </Button>
            }
        >
            <Head title={t('knowledge.title')} />

            <FlashBanner />

            <FilterBar
                search={search}
                onSearchChange={setSearch}
                searchPlaceholder={t('knowledge.searchPlaceholder')}
                searchAriaLabel={t('knowledge.searchAria')}
                searchClassName="w-full sm:w-72"
                visible={results !== undefined ? results.length : pages.length}
                total={pages.length}
            />

            {searchUnavailable ? (
                <Banner variant="warning" className="mb-4">
                    {t('knowledge.searchUnavailable')}
                </Banner>
            ) : null}

            <div className="grid items-start gap-6 lg:grid-cols-[16rem_1fr]">
                <KnowledgeSidebar
                    pages={pages}
                    projects={projects}
                    selectedId={selectedId}
                    projectFilter={projectFilter ?? ''}
                    onProjectFilterChange={setProjectFilter}
                    onCreatePage={() =>
                        openCreating({
                            projectId:
                                projectFilter && projectFilter !== 'transverse'
                                    ? projectFilter
                                    : '',
                        })
                    }
                />

                <div className="min-w-0">
                    {results !== undefined ? (
                        <section aria-label={t('knowledge.searchAria')}>
                            <div className="mb-3 flex items-center gap-2">
                                <h2 className="text-base font-semibold">
                                    {t('knowledge.resultsFor', { query })}
                                </h2>
                                <span className="rounded-full bg-secondary px-2 py-0.5 font-mono text-[11px] text-muted-foreground tabular-nums">
                                    {results.length}
                                </span>
                            </div>
                            {results.length === 0 ? (
                                <div className="rounded-xl border border-dashed px-4 py-12 text-center">
                                    <Search
                                        className="mx-auto size-8 text-muted-foreground/50"
                                        aria-hidden
                                    />
                                    <p className="mt-3 font-medium">
                                        {t('knowledge.noMatchingPages')}
                                    </p>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {t('knowledge.noMatchingHint')}
                                    </p>
                                    <div className="mt-4 flex flex-wrap items-center justify-center gap-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                openCreating({ title: query ?? '' })
                                            }
                                        >
                                            <Plus aria-hidden />
                                            {t('knowledge.createPageWithTitle', {
                                                title: query ?? '',
                                            })}
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => setSearch('')}
                                        >
                                            {t('knowledge.clearSearch')}
                                        </Button>
                                    </div>
                                </div>
                            ) : (
                                <ul className="grid gap-3">
                                    {results.map((result) => (
                                        <li key={result.id}>
                                            <Link
                                                href={
                                                    projectFilter
                                                        ? route('knowledge.show', { page: result.id, project: projectFilter })
                                                        : route('knowledge.show', result.id)
                                                }
                                                className="block rounded-xl border bg-card p-4 transition-colors hover:border-primary/45"
                                            >
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="font-medium">{result.title}</span>
                                                    {result.projectId ? (
                                                        <Badge variant="secondary">
                                                            {projectName(result.projectId)}
                                                        </Badge>
                                                    ) : null}
                                                    <Badge
                                                        variant="outline"
                                                        className="ml-auto font-mono text-[11px] tabular-nums"
                                                        title={result.score.toFixed(3)}
                                                    >
                                                        {Math.round(result.score * 100)}%
                                                    </Badge>
                                                </div>
                                                <p className="mt-1.5 text-sm text-muted-foreground">
                                                    {result.excerpt}
                                                </p>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    ) : null}

                    {selected ? (
                        <div className={results !== undefined ? 'mt-6' : ''}>
                            {conflict ? (
                                <div
                                    role="alert"
                                    className="mb-4 flex items-start gap-2.5 rounded-lg border border-warning/35 bg-warning/10 px-3.5 py-3 text-sm"
                                >
                                    <TriangleAlert
                                        className="mt-0.5 size-4 shrink-0 text-warning"
                                        aria-hidden
                                    />
                                    <div className="min-w-0 flex-1">
                                        <p className="font-medium">
                                            {t('knowledge.conflict.title')}
                                        </p>
                                        <p className="mt-1 text-muted-foreground">
                                            {t('knowledge.conflict.description', {
                                                editor: conflict.lastEditor ?? 'unknown',
                                            })}
                                        </p>
                                        <div className="mt-3 flex gap-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() => submitEdit(true)}
                                            >
                                                {t('knowledge.conflict.overwrite')}
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => {
                                                    setConflict(null);
                                                    router.reload({ only: ['selected'] });
                                                }}
                                            >
                                                {t('knowledge.conflict.reload')}
                                            </Button>
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => setConflict(null)}
                                        className="text-muted-foreground transition-colors hover:text-foreground"
                                        aria-label={t('knowledge.conflict.dismiss')}
                                    >
                                        <X className="size-4" aria-hidden />
                                    </button>
                                </div>
                            ) : null}
                            <article className="overflow-hidden rounded-xl border bg-card">
                            <header className="flex flex-wrap items-center gap-x-2.5 gap-y-2 border-b px-5 py-3.5">
                                <nav
                                    aria-label={t('knowledge.breadcrumbAria')}
                                    className="flex w-full flex-wrap items-center gap-1.5 text-[13px] text-muted-foreground"
                                >
                                    {selected.projectId ? (
                                        <span className="flex items-center gap-1">
                                            <Folder className="size-3.5 shrink-0" aria-hidden />
                                            {projectName(selected.projectId)}
                                        </span>
                                    ) : (
                                        <span className="flex items-center gap-1">
                                            <Globe className="size-3.5 shrink-0" aria-hidden />
                                            {t('knowledge.crossWorkspace')}
                                        </span>
                                    )}
                                    {selected.parentChain.map((ancestor) => (
                                        <span key={ancestor.id} className="flex min-w-0 items-center gap-1.5">
                                            <ChevronRight className="size-3 shrink-0" aria-hidden />
                                            <Link
                                                href={
                                                    projectFilter
                                                        ? route('knowledge.show', { page: ancestor.id, project: projectFilter })
                                                        : route('knowledge.show', ancestor.id)
                                                }
                                                className="truncate transition-colors hover:text-foreground"
                                            >
                                                {ancestor.title}
                                            </Link>
                                        </span>
                                    ))}
                                    <span className="flex min-w-0 items-center gap-1.5">
                                        <ChevronRight className="size-3 shrink-0" aria-hidden />
                                        <span className="truncate font-medium text-foreground">
                                            {selected.title}
                                        </span>
                                    </span>
                                </nav>
                                <h2 className="text-base font-semibold tracking-tight">{selected.title}</h2>
                                {selected.projectId ? (
                                    <Badge variant="secondary" className="gap-1">
                                        <Folder className="size-3" aria-hidden />
                                        {projectName(selected.projectId)}
                                    </Badge>
                                ) : (
                                    <Badge variant="outline" className="gap-1">
                                        <Globe className="size-3" aria-hidden />
                                        {t('knowledge.crossWorkspace')}
                                    </Badge>
                                )}
                                <div className="ml-auto flex items-center gap-1">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            router.get(route('knowledge.versions', selected.id))
                                        }
                                    >
                                        <History aria-hidden />
                                        {t('knowledge.versions.button')}
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => {
                                            setBaseUpdatedAt(selected.updatedAt);
                                            setConflict(null);
                                            setEditing(true);
                                            editForm.setData({
                                                title: selected.title,
                                                body: selected.body,
                                                project_id: selected.projectId ?? '',
                                                parent_id: selected.parentId ?? '',
                                            });
                                        }}
                                    >
                                        <Pencil aria-hidden />
                                        {t('issueShow.edit')}
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                        onClick={() => setDeleting(true)}
                                    >
                                        <Trash2 aria-hidden />
                                        {t('common.delete')}
                                    </Button>
                                </div>
                                <p className="flex w-full flex-wrap items-center gap-x-3 gap-y-1 text-[13px] text-muted-foreground">
                                    {selected.lastEditor ? (
                                        <span className="flex items-center gap-1.5">
                                            <User className="size-3.5 shrink-0" aria-hidden />
                                            {t('knowledge.editedBy', { name: selected.lastEditor })}
                                        </span>
                                    ) : null}
                                    {selected.updatedAt ? (
                                        <span
                                            className="flex items-center gap-1.5"
                                            title={formatDate(selected.updatedAt)}
                                        >
                                            <Clock className="size-3.5 shrink-0" aria-hidden />
                                            {formatRelativeDate(selected.updatedAt)}
                                        </span>
                                    ) : null}
                                </p>
                            </header>
                            <div className="px-5 py-5">
                                <MarkdownView value={selected.body} />
                            </div>
                            </article>
                        </div>
                    ) : results === undefined ? (
                        <div className="rounded-xl border border-dashed px-4 py-12 text-center">
                            <BookOpen
                                className="mx-auto size-10 text-muted-foreground/50"
                                aria-hidden
                            />
                            <p className="mt-3 font-medium">{t('knowledge.noPageSelected')}</p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {t('knowledge.noPageSelectedHint')}
                            </p>
                            <Button size="sm" className="mt-4" onClick={() => openCreating()}>
                                <Plus aria-hidden />
                                {t('knowledge.newPage')}
                            </Button>
                        </div>
                    ) : null}
                </div>
            </div>

            <Dialog
                open={deleting}
                onOpenChange={(open) => {
                    if (!open) setDeleting(false);
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('knowledge.deleteTitle', { title: selected?.title })}</DialogTitle>
                        <DialogDescription>
                            {t('knowledge.deleteDescription')}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setDeleting(false)}>
                            {t('common.cancel')}
                        </Button>
                        <Button variant="destructive" data-test="confirm-delete-knowledge" onClick={remove}>
                            {t('common.delete')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <FormDialog
                open={creating}
                onOpenChange={setCreating}
                title={t('knowledge.newPageTitle')}
                description={t('knowledge.newPageDescription')}
                submitLabel={t('common.create')}
                processing={createForm.processing}
                contentClassName="max-h-[90vh] overflow-y-auto sm:max-w-2xl"
                onSubmit={submitCreate}
            >
                <div className="grid gap-2">
                    <Label htmlFor="new-knowledge-title">{t('issues.fields.title')}</Label>
                    <Input
                        id="new-knowledge-title"
                        value={createForm.data.title}
                        onChange={(e) => createForm.setData('title', e.target.value)}
                        required
                        autoFocus
                        aria-invalid={createForm.errors.title ? true : undefined}
                    />
                    {createForm.errors.title ? (
                        <p className="text-sm text-destructive">{createForm.errors.title}</p>
                    ) : null}
                </div>
                <div className="grid gap-2">
                    <Label>{t('knowledge.body')}</Label>
                    <MarkdownEditor
                        value={createForm.data.body}
                        onChange={(markdown) => createForm.setData('body', markdown)}
                    />
                    {createForm.errors.body ? (
                        <p className="text-sm text-destructive">{createForm.errors.body}</p>
                    ) : null}
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="new-knowledge-project">{t('issues.fields.project')}</Label>
                        {projectSelect(createForm, 'new-knowledge')}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="new-knowledge-parent">{t('knowledge.parentPage')}</Label>
                        {parentSelect(createForm, 'new-knowledge')}
                    </div>
                </div>
            </FormDialog>

            <FormDialog
                open={editing}
                onOpenChange={setEditing}
                title={t('knowledge.editPageTitle')}
                description={t('knowledge.editPageDescription')}
                submitLabel={t('common.save')}
                processing={editForm.processing}
                contentClassName="max-h-[90vh] overflow-y-auto sm:max-w-2xl"
                onSubmit={(e) => {
                    e.preventDefault();
                    submitEdit(false);
                }}
            >
                <div className="grid gap-2">
                    <Label htmlFor="edit-knowledge-title">{t('issues.fields.title')}</Label>
                    <Input
                        id="edit-knowledge-title"
                        value={editForm.data.title}
                        onChange={(e) => editForm.setData('title', e.target.value)}
                        required
                        autoFocus
                        aria-invalid={editForm.errors.title ? true : undefined}
                    />
                    {editForm.errors.title ? (
                        <p className="text-sm text-destructive">{editForm.errors.title}</p>
                    ) : null}
                </div>
                <div className="grid gap-2">
                    <Label>{t('knowledge.body')}</Label>
                    <MarkdownEditor
                        value={editForm.data.body}
                        onChange={(markdown) => editForm.setData('body', markdown)}
                    />
                    {editForm.errors.body ? (
                        <p className="text-sm text-destructive">{editForm.errors.body}</p>
                    ) : null}
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="edit-knowledge-project">{t('issues.fields.project')}</Label>
                        {projectSelect(editForm, 'edit-knowledge')}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="edit-knowledge-parent">{t('knowledge.parentPage')}</Label>
                        {parentSelect(editForm, 'edit-knowledge', selected?.id)}
                    </div>
                </div>
            </FormDialog>
        </AppLayout>
    );
}

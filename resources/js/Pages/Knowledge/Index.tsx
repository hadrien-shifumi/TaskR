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
import { KnowledgeParentPicker } from '@/components/knowledge-parent-picker';
import { MarkdownView } from '@/components/ui/markdown-view';
// TipTap (the markdown editor's engine) is a heavy dependency: it rides a
// lazy chunk loaded on first editor open, not with the page bundle.
const MarkdownEditor = lazy(() =>
    import('@/components/ui/markdown-editor').then((m) => ({ default: m.MarkdownEditor })),
);
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
    ChevronDown,
    ChevronRight,
    Clock,
    FileText,
    Folder,
    Globe,
    History,
    ListTree,
    Pencil,
    Plus,
    Search,
    Trash2,
    TriangleAlert,
    User,
    X,
} from 'lucide-react';
import { lazy, Suspense, useEffect, useId, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { PageSharedProps } from '@/types';
import { formatDate, formatRelativeDate } from '@/lib/format-date';
import { countMarkdownWords, readingMinutes } from '@/lib/word-count';
import { cn } from '@/lib/utils';
import { KnowledgeSidebar, type PageRef, type ProjectOption } from './components/knowledge-sidebar';
import {
    extractHeadings,
    slugifyHeading,
    type MarkdownHeading,
} from '@/components/ui/markdown-view';

interface SelectedPage {
    id: string;
    title: string;
    body: string;
    parentId: string | null;
    projectId: string | null;
    parentChain: { id: string; title: string }[];
    children: PageRef[];
    lastEditor: string | null;
    updatedAt: string | null;
}

interface SearchResult {
    id: string;
    title: string;
    excerpt: string;
    score: number;
    projectId: string | null;
    parentChain: { id: string; title: string }[];
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

const emptyForm = { title: '', body: '', project_id: '', parent_id: '' };

/** Local draft of an in-progress edit — survives an accidental close. */
type KnowledgeDraft = { title: string; body: string; savedAt: string };

const draftKey = (pageId: string): string => `knowledge-draft:${pageId}`;

function readDraft(pageId: string): KnowledgeDraft | null {
    try {
        const raw = localStorage.getItem(draftKey(pageId));
        if (raw === null) {
            return null;
        }
        const parsed = JSON.parse(raw) as Partial<KnowledgeDraft>;
        if (
            typeof parsed.title !== 'string' ||
            typeof parsed.body !== 'string' ||
            typeof parsed.savedAt !== 'string'
        ) {
            return null;
        }
        return { title: parsed.title, body: parsed.body, savedAt: parsed.savedAt };
    } catch {
        return null;
    }
}

function writeDraft(pageId: string, title: string, body: string): void {
    try {
        localStorage.setItem(
            draftKey(pageId),
            JSON.stringify({ title, body, savedAt: new Date().toISOString() }),
        );
    } catch {
        // Storage unavailable or full: the draft is best-effort.
    }
}

function discardDraft(pageId: string): void {
    try {
        localStorage.removeItem(draftKey(pageId));
    } catch {
        // Ignore.
    }
}

/**
 * Splits an excerpt into literal-match parts: tokens of the query are
 * kept as highlight markers, the rest as plain text. Returns React nodes —
 * never HTML — so nothing is injected raw into the DOM.
 */
function highlightExcerpt(excerpt: string, query: string): React.ReactNode[] {
    const tokens = query
        .trim()
        .split(/\s+/)
        .filter((token) => token.length >= 2)
        .map((token) => token.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));

    if (tokens.length === 0 || excerpt.trim() === '') {
        return [excerpt];
    }

    const pattern = new RegExp(`(${tokens.join('|')})`, 'gi');
    return excerpt.split(pattern).map((part, index) =>
        index % 2 === 1 ? (
            <mark key={index} className="rounded bg-primary/20 px-0.5 text-foreground">
                {part}
            </mark>
        ) : (
            <span key={index}>{part}</span>
        ),
    );
}

/** Breadcrumb path of a result: project (or transverse) then ancestors. */
function ResultPath({ result }: { result: SearchResult }) {
    const { t } = useTranslation();
    const chain = result.parentChain ?? [];

    if (result.projectId === null && chain.length === 0) {
        return null;
    }

    return (
        <p className="mt-1 flex min-w-0 flex-wrap items-center gap-1 text-xs text-muted-foreground">
            {result.projectId === null ? (
                <span className="flex items-center gap-1">
                    <Globe className="size-3 shrink-0" aria-hidden />
                    {t('knowledge.crossWorkspace')}
                </span>
            ) : null}
            {chain.map((ancestor, index) => (
                <span
                    key={ancestor.id}
                    className={result.projectId === null || index > 0 ? 'flex items-center gap-1' : undefined}
                >
                    {result.projectId === null || index > 0 ? (
                        <ChevronRight className="size-3 shrink-0" aria-hidden />
                    ) : null}
                    <span className="max-w-40 truncate">{ancestor.title}</span>
                </span>
            ))}
        </p>
    );
}

/** "On this page" TOC: a sticky right rail on xl+, a collapsible block above the article below. */
function OnThisPage({
    headings,
    variant,
}: {
    headings: MarkdownHeading[];
    variant: 'mobile' | 'desktop';
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const reactId = useId();
    const listId = `knowledge-toc-list-${variant}-${reactId.replace(/[^a-zA-Z0-9-]/g, '')}`;

    if (headings.length === 0) {
        return null;
    }

    if (variant === 'desktop') {
        return (
            <nav
                aria-label={t('knowledge.onThisPage')}
                className="hidden xl:block xl:sticky xl:top-[calc(80px+env(safe-area-inset-top))]"
            >
                <p className="mb-2 flex items-center gap-2 font-mono text-[12px] tracking-[0.08em] text-muted-foreground uppercase">
                    <ListTree className="size-3.5 shrink-0" aria-hidden />
                    {t('knowledge.onThisPage')}
                </p>
                <ul className="space-y-1 border-l pl-4">
                    {headings.map((heading) => (
                        <li key={heading.id}>
                            <a
                                href={`#${heading.id}`}
                                onClick={(event) => {
                                    event.preventDefault();
                                    document
                                        .getElementById(heading.id)
                                        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                                }}
                                className={`block truncate py-1 text-sm text-muted-foreground transition-colors hover:text-foreground ${
                                    heading.level === 1 ? '' : heading.level === 2 ? 'pl-3' : 'pl-6'
                                }`}
                            >
                                {heading.text}
                            </a>
                        </li>
                    ))}
                </ul>
            </nav>
        );
    }

    return (
        <nav aria-label={t('knowledge.onThisPage')} className="mb-4 xl:hidden">
            <button
                type="button"
                onClick={() => setOpen((previous) => !previous)}
                aria-expanded={open}
                aria-controls={open ? listId : undefined}
                className="flex w-full items-center justify-between rounded-xl border bg-card px-4 py-3 text-sm font-medium"
            >
                <span className="flex items-center gap-2">
                    <ListTree className="size-4 text-muted-foreground" aria-hidden />
                    {t('knowledge.onThisPage')}
                </span>
                <ChevronDown
                    className={`size-4 text-muted-foreground transition-transform ${open ? 'rotate-180' : ''}`}
                    aria-hidden
                />
            </button>
            {open ? (
                <ul id={listId} className="mt-2 space-y-1 border-l pl-4">
                    {headings.map((heading) => (
                        <li key={heading.id}>
                            <a
                                href={`#${heading.id}`}
                                onClick={(event) => {
                                    event.preventDefault();
                                    document
                                        .getElementById(heading.id)
                                        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                                }}
                                className={`block truncate py-1 text-sm text-muted-foreground transition-colors hover:text-foreground ${
                                    heading.level === 1 ? '' : heading.level === 2 ? 'pl-3' : 'pl-6'
                                }`}
                            >
                                {heading.text}
                            </a>
                        </li>
                    ))}
                </ul>
            ) : null}
        </nav>
    );
}

/**
 * "Recently updated" — the no-scope landing content: the five pages of
 * the workspace's perimeter touched last, client-side sorted from the
 * `updatedAt` the list payload already carries.
 */
function RecentlyUpdated({
    pages,
    projectName,
}: {
    pages: PageRef[];
    projectName: (projectId: string | null) => string | null;
}) {
    const { t } = useTranslation();

    const recent = [...pages]
        .filter((page) => page.updatedAt !== null)
        .sort((a, b) => new Date(b.updatedAt!).getTime() - new Date(a.updatedAt!).getTime())
        .slice(0, 5);

    if (recent.length === 0) {
        return null;
    }

    return (
        <section aria-label={t('knowledge.recentlyUpdated')} data-test="knowledge-recent-pages">
            <div className="mb-3 flex items-center gap-2">
                <h2 className="text-base font-semibold">
                    {t('knowledge.recentlyUpdated')}
                </h2>
                <span className="rounded-full bg-secondary px-2 py-0.5 font-mono text-[11px] text-muted-foreground tabular-nums">
                    {recent.length}
                </span>
            </div>
            <p className="mb-4 text-sm text-muted-foreground">
                {t('knowledge.recentlyUpdatedHint')}
            </p>
            <ul className="grid gap-3 sm:grid-cols-2">
                {recent.map((page) => (
                    <li key={page.id}>
                        <Link
                            href={route('knowledge.show', page.id)}
                            className="block rounded-xl border bg-card p-4 transition-colors hover:border-primary/45"
                        >
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-medium">{page.title}</span>
                                {page.projectId ? (
                                    <Badge variant="secondary">
                                        {projectName(page.projectId)}
                                    </Badge>
                                ) : (
                                    <Badge variant="outline">
                                        {t('knowledge.crossWorkspace')}
                                    </Badge>
                                )}
                            </div>
                            <span className="mt-1 block text-xs text-muted-foreground">
                                {formatRelativeDate(page.updatedAt)}
                            </span>
                        </Link>
                    </li>
                ))}
            </ul>
        </section>
    );
}

/** Child pages of the article, as clickable cards — empty section hides. */
function ChildPages({ children: childPages }: { children: PageRef[] }) {
    const { t } = useTranslation();

    if (childPages.length === 0) {
        return null;
    }

    return (
        <section aria-label={t('knowledge.childPages')} className="mt-6 border-t pt-5">
            <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold">
                <FileText className="size-4 text-muted-foreground" aria-hidden />
                {t('knowledge.childPages')}
            </h3>
            <ul className="grid gap-3 sm:grid-cols-2">
                {childPages.map((child) => (
                    <li key={child.id}>
                        <Link
                            href={
                                child.projectId
                                    ? route('knowledge.show', { page: child.id, project: child.projectId })
                                    : route('knowledge.show', child.id)
                            }
                            className="block rounded-xl border bg-card p-4 transition-colors hover:border-primary/45"
                        >
                            <span className="block truncate font-medium">{child.title}</span>
                            <span className="mt-1 block text-xs text-muted-foreground">
                                {formatRelativeDate(child.updatedAt)}
                            </span>
                        </Link>
                    </li>
                ))}
            </ul>
        </section>
    );
}

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
    // Full-page editor: write / split / preview view, and the folded
    // "Options" section (project + parent).
    const [editMode, setEditMode] = useState<'write' | 'split' | 'preview'>('write');
    const [optionsOpen, setOptionsOpen] = useState(false);
    const [draftProposal, setDraftProposal] = useState<KnowledgeDraft | null>(null);

    const createForm = useForm({ ...emptyForm });
    const editForm = useForm({ ...emptyForm });

    const searchInputRef = useRef<HTMLInputElement>(null);

    // Headings of the selected page — anchors for the "On this page" TOC.
    const headings = useMemo(
        () => (selected ? extractHeadings(selected.body) : []),
        [selected],
    );

    // Reading metadata of the article footer: prose words of the markdown
    // body (syntax stripped) drive the estimated reading time.
    const wordCount = useMemo(
        () => (selected ? countMarkdownWords(selected.body) : 0),
        [selected],
    );

    const selectedId = selected?.id ?? null;
    const projectName = (projectId: string | null): string | null =>
        projectId ? (projects.find((project) => project.id === projectId)?.name ?? null) : null;

    /** Opens the create dialog, optionally pre-filling title/project/parent. */
    const openCreating = (init?: { title?: string; projectId?: string; parentId?: string }) => {
        if (init) {
            createForm.setData({
                ...emptyForm,
                title: init.title ?? '',
                project_id: init.projectId ?? '',
                parent_id: init.parentId ?? '',
            });
        }
        setCreating(true);
    };

    /** Sets the sidebar project filter; an empty value removes the param.
     *  Partial reload: the tree, the selection and the filter refetch, the
     *  rest of the shell stays behind. */
    const setProjectFilter = (value: string) => {
        const params: Record<string, string> = {};
        if (value) {
            params.project = value;
        }
        router.get(route('knowledge.index'), params, {
            preserveState: true,
            preserveScroll: true,
            only: ['pages', 'selected', 'projectFilter'],
        });
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

    // Keyboard shortcuts: "/" focuses the search (unless typing in a field
    // or a dialog is open), Escape clears it and gives focus back. The
    // editor and dialogs catch their own keys first.
    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            const target = event.target as HTMLElement | null;
            const isTypingTarget =
                target !== null &&
                (target.tagName === 'INPUT' ||
                    target.tagName === 'TEXTAREA' ||
                    target.isContentEditable);

            if (event.key === '/' && !isTypingTarget) {
                if (document.querySelector('[role="dialog"]')) {
                    return;
                }
                event.preventDefault();
                searchInputRef.current?.focus();
                searchInputRef.current?.select();
                return;
            }

            if (
                event.key === 'Escape' &&
                target === searchInputRef.current &&
                search !== ''
            ) {
                event.preventDefault();
                setSearch('');
                searchInputRef.current?.blur();
            }
        };

        window.addEventListener('keydown', onKeyDown);
        return () => window.removeEventListener('keydown', onKeyDown);
    }, [search]);

    /** Opens the full-page editor; a differing local draft is proposed, never forced. */
    const openEdit = () => {
        if (!selected) return;

        // The conflict reference is always the page as it stands now —
        // never the draft's, which carries title and body only.
        setBaseUpdatedAt(selected.updatedAt);
        setConflict(null);
        setEditMode('write');
        setOptionsOpen(false);
        editForm.setData({
            title: selected.title,
            body: selected.body,
            project_id: selected.projectId ?? '',
            parent_id: selected.parentId ?? '',
        });

        const stored = readDraft(selected.id);
        setDraftProposal(
            stored && (stored.title !== selected.title || stored.body !== selected.body)
                ? stored
                : null,
        );
        setEditing(true);
    };

    // Draft auto-save: 500 ms after the last change, the title and body
    // land in the browser storage so an accidental close loses nothing.
    // The draft is purged on a successful save (see submitEdit).
    useEffect(() => {
        if (!editing || !selected) {
            return;
        }
        const timer = setTimeout(() => {
            writeDraft(selected.id, editForm.data.title, editForm.data.body);
        }, 500);
        return () => clearTimeout(timer);
    }, [editing, selected, editForm.data.title, editForm.data.body]);

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
                // The save succeeded: the safety draft has done its job.
                discardDraft(selected.id);
                setDraftProposal(null);
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

    /**
     * Sidebar drag & drop: re-parent only, never re-project — the move
     * endpoint refuses a parent of another project, the client already
     * blocked self/descendant/cross-project drops.
     */
    const movePage = (pageId: string, parentId: string | null) => {
        router.patch(route('knowledge.move', pageId), { parent_id: parentId }, {
            preserveScroll: true,
        });
    };

    /**
     * Title of a parent candidate for the picker's input. The sidebar
     * list is project-filtered: when the parent sits outside the active
     * filter, the open page's own breadcrumb chain still names it.
     */
    const parentTitle = (parentId: string | null): string | null => {
        if (!parentId) {
            return null;
        }
        const fromList = pages.find((page) => page.id === parentId)?.title;
        if (fromList) {
            return fromList;
        }
        if (selected && parentId === selected.parentId) {
            return selected.parentChain.at(-1)?.title ?? null;
        }
        return null;
    };

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
                searchInputRef={searchInputRef}
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
                    onCreatePageInProject={(projectId) => openCreating({ projectId: projectId ?? '' })}
                    onMovePage={movePage}
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
                                                <ResultPath result={result} />
                                                <p className="mt-1.5 text-sm text-muted-foreground">
                                                    {highlightExcerpt(result.excerpt, query ?? '')}
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
                            {editing ? (
                                <form
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        submitEdit(false);
                                    }}
                                    className="flex flex-col rounded-xl border bg-card"
                                    data-test="knowledge-edit-form"
                                >
                                    <header className="flex flex-wrap items-center gap-x-2.5 gap-y-2 border-b px-5 py-3.5">
                                        <nav
                                            aria-label={t('knowledge.breadcrumbAria')}
                                            className="flex min-w-0 flex-1 flex-wrap items-center gap-1.5 text-[13px] text-muted-foreground"
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
                                                    <span className="truncate">{ancestor.title}</span>
                                                </span>
                                            ))}
                                        </nav>
                                        <div className="ml-auto flex items-center gap-2">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                onClick={() => setEditing(false)}
                                            >
                                                {t('common.cancel')}
                                            </Button>
                                            <Button
                                                type="submit"
                                                disabled={editForm.processing}
                                                data-test="edit-knowledge-save"
                                            >
                                                {t('common.save')}
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
                                    {draftProposal ? (
                                        <div
                                            role="status"
                                            data-test="knowledge-draft-banner"
                                            className="m-5 mb-0 flex items-start gap-2.5 rounded-lg border border-warning/35 bg-warning/10 px-3.5 py-3 text-sm"
                                        >
                                            <History
                                                className="mt-0.5 size-4 shrink-0 text-warning"
                                                aria-hidden
                                            />
                                            <div className="min-w-0 flex-1">
                                                <p className="font-medium">
                                                    {t('knowledge.editing.draftFound')}
                                                </p>
                                                <p className="mt-0.5 text-muted-foreground">
                                                    {formatRelativeDate(draftProposal.savedAt)}
                                                </p>
                                                <div className="mt-2.5 flex gap-2">
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        data-test="knowledge-draft-restore"
                                                        onClick={() => {
                                                            editForm.setData({
                                                                ...editForm.data,
                                                                title: draftProposal.title,
                                                                body: draftProposal.body,
                                                            });
                                                            setDraftProposal(null);
                                                        }}
                                                    >
                                                        {t('knowledge.editing.draftRestore')}
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        data-test="knowledge-draft-discard"
                                                        onClick={() => {
                                                            discardDraft(selected.id);
                                                            setDraftProposal(null);
                                                        }}
                                                    >
                                                        {t('knowledge.editing.draftDismiss')}
                                                    </Button>
                                                </div>
                                            </div>
                                            <button
                                                type="button"
                                                onClick={() => setDraftProposal(null)}
                                                className="text-muted-foreground transition-colors hover:text-foreground"
                                                aria-label={t('knowledge.conflict.dismiss')}
                                            >
                                                <X className="size-4" aria-hidden />
                                            </button>
                                        </div>
                                    ) : null}
                                    <div className="px-5 pt-5">
                                        <label htmlFor="edit-knowledge-title" className="sr-only">
                                            {t('issues.fields.title')}
                                        </label>
                                        <Input
                                            id="edit-knowledge-title"
                                            value={editForm.data.title}
                                            onChange={(e) => editForm.setData('title', e.target.value)}
                                            required
                                            placeholder={t('issues.fields.title')}
                                            aria-invalid={editForm.errors.title ? true : undefined}
                                            className="h-auto border-0 bg-transparent px-0 text-xl font-semibold shadow-none focus-visible:ring-0 md:text-2xl"
                                        />
                                        {editForm.errors.title ? (
                                            <p className="text-sm text-destructive">{editForm.errors.title}</p>
                                        ) : null}
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2 px-5 pt-3">
                                        <div
                                            className="inline-flex rounded-lg border bg-muted/40 p-0.5"
                                            role="group"
                                            aria-label={t('knowledge.editing.viewLabel')}
                                        >
                                            {(['write', 'split', 'preview'] as const).map((mode) => (
                                                <button
                                                    key={mode}
                                                    type="button"
                                                    onClick={() => setEditMode(mode)}
                                                    aria-pressed={editMode === mode}
                                                    data-test={`knowledge-edit-view-${mode}`}
                                                    className={cn(
                                                        'rounded-md px-3 py-1 text-sm transition-colors',
                                                        mode === 'split' && 'hidden sm:block',
                                                        editMode === mode
                                                            ? 'bg-background font-medium text-foreground shadow-sm'
                                                            : 'text-muted-foreground hover:text-foreground',
                                                    )}
                                                >
                                                    {t(`knowledge.editing.mode.${mode}`)}
                                                </button>
                                            ))}
                                        </div>
                                    </div>
                                    <div className="min-h-0 px-5 pt-3">
                                        {editMode === 'write' ? (
                                            <div className="flex h-[60vh] min-h-[18rem] flex-col">
                                                <Suspense
                                                    fallback={
                                                        <div className="min-h-48 flex-1 animate-pulse rounded-lg border bg-muted/40" aria-hidden="true" />
                                                    }
                                                >
                                                    <MarkdownEditor
                                                        value={editForm.data.body}
                                                        onChange={(markdown) => editForm.setData('body', markdown)}
                                                        fill
                                                    />
                                                </Suspense>
                                            </div>
                                        ) : editMode === 'split' ? (
                                            <div className="grid h-[60vh] min-h-[18rem] gap-4 md:grid-cols-2">
                                                <div className="flex min-h-0 flex-col max-md:min-h-[14rem]">
                                                    <Suspense
                                                        fallback={
                                                            <div className="min-h-48 flex-1 animate-pulse rounded-lg border bg-muted/40" aria-hidden="true" />
                                                        }
                                                    >
                                                        <MarkdownEditor
                                                            value={editForm.data.body}
                                                            onChange={(markdown) => editForm.setData('body', markdown)}
                                                            fill
                                                        />
                                                    </Suspense>
                                                </div>
                                                <div className="min-h-0 overflow-y-auto rounded-lg border px-4 py-3 max-md:min-h-[14rem]">
                                                    <MarkdownView value={editForm.data.body} />
                                                </div>
                                            </div>
                                        ) : (
                                            <div className="h-[60vh] min-h-[18rem] overflow-y-auto rounded-lg border px-4 py-3">
                                                <MarkdownView value={editForm.data.body} />
                                            </div>
                                        )}
                                        {editForm.errors.body ? (
                                            <p className="mt-2 text-sm text-destructive">{editForm.errors.body}</p>
                                        ) : null}
                                    </div>
                                    <div className="px-5 py-4">
                                        <button
                                            type="button"
                                            onClick={() => setOptionsOpen((open) => !open)}
                                            aria-expanded={optionsOpen}
                                            data-test="knowledge-edit-options-toggle"
                                            className="flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                                        >
                                            <ChevronRight
                                                className={cn('size-3.5 transition-transform', optionsOpen && 'rotate-90')}
                                                aria-hidden
                                            />
                                            {t('knowledge.editing.options')}
                                        </button>
                                        {optionsOpen ? (
                                            <div className="mt-3 grid gap-4 rounded-lg border bg-muted/20 p-4 sm:grid-cols-2">
                                                <div className="grid gap-2">
                                                    <Label htmlFor="edit-knowledge-project">
                                                        {t('issues.fields.project')}
                                                    </Label>
                                                    {projectSelect(editForm, 'edit-knowledge')}
                                                </div>
                                                <KnowledgeParentPicker
                                                    value={editForm.data.parent_id}
                                                    onChange={(id) => editForm.setData('parent_id', id)}
                                                    currentTitle={parentTitle(editForm.data.parent_id)}
                                                    excludeId={selected.id}
                                                    inputId="edit-knowledge-parent"
                                                />
                                                {editForm.errors.project_id ? (
                                                    <p className="text-sm text-destructive">{editForm.errors.project_id}</p>
                                                ) : null}
                                                {editForm.errors.parent_id ? (
                                                    <p className="text-sm text-destructive">{editForm.errors.parent_id}</p>
                                                ) : null}
                                            </div>
                                        ) : null}
                                    </div>
                                </form>
                            ) : (
                            <>
                            {headings.length > 0 ? (
                                <div className="mb-4 xl:hidden">
                                    <OnThisPage headings={headings} variant="mobile" />
                                </div>
                            ) : null}
                            <div className="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_15rem]">
                                <article className="min-w-0 overflow-hidden rounded-xl border bg-card">
                                {/* Breadcrumb: project + ancestors only — the
                                    h2 below carries the title, no repeat. */}
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
                                    </nav>
                                    <div className="ml-auto flex items-center gap-1">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            data-test="new-sub-page"
                                            onClick={() =>
                                                openCreating({ parentId: selected.id, projectId: selected.projectId ?? '' })
                                            }
                                        >
                                            <Plus aria-hidden />
                                            {t('knowledge.newSubPage')}
                                        </Button>
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
                                            onClick={openEdit}
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
                                <div className="mx-auto max-w-3xl px-5 py-5">
                                    <h2 className="text-base font-semibold tracking-tight">
                                        {selected.title}
                                    </h2>
                                    <MarkdownView value={selected.body} />
                                </div>
                                {/* Reading metadata: prose words and estimated
                                    reading time, computed client-side from the
                                    raw markdown. */}
                                <footer
                                    data-test="knowledge-reading-meta"
                                    className="flex flex-wrap items-center gap-x-2 gap-y-1 border-t px-5 py-3 text-xs text-muted-foreground"
                                >
                                    <FileText className="size-3.5 shrink-0" aria-hidden />
                                    <span data-test="knowledge-word-count">
                                        {wordCount > 1
                                            ? t('knowledge.wordCountPlural', { count: wordCount })
                                            : t('knowledge.wordCount', { count: wordCount })}
                                    </span>
                                    <span aria-hidden>·</span>
                                    <span data-test="knowledge-reading-time">
                                        {t('knowledge.readingTime', { minutes: readingMinutes(wordCount) })}
                                    </span>
                                </footer>
                                <ChildPages>{selected.children ?? []}</ChildPages>
                                </article>
                                {headings.length > 0 ? (
                                    <div className="hidden xl:block">
                                        <OnThisPage headings={headings} variant="desktop" />
                                    </div>
                                ) : null}
                            </div>
                            </>
                            )}
                        </div>
                    ) : results === undefined ? (
                        projectFilter ? (
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
                        ) : pages.length > 0 ? (
                            // No scope chosen: real content instead of a passive
                            // empty state — the five most recently touched pages.
                            <div>
                                <RecentlyUpdated pages={pages} projectName={projectName} />
                                <Button size="sm" className="mt-6" onClick={() => openCreating()}>
                                    <Plus aria-hidden />
                                    {t('knowledge.newPage')}
                                </Button>
                            </div>
                        ) : (
                            <div className="rounded-xl border border-dashed px-4 py-12 text-center">
                                <BookOpen
                                    className="mx-auto size-10 text-muted-foreground/50"
                                    aria-hidden
                                />
                                <p className="mt-3 font-medium">{t('knowledge.noPages')}</p>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {t('knowledge.noPagesHint')}
                                </p>
                                <Button size="sm" className="mt-4" onClick={() => openCreating()}>
                                    <Plus aria-hidden />
                                    {t('knowledge.newPage')}
                                </Button>
                            </div>
                        )
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
                    <Suspense
                        fallback={
                            <div className="min-h-48 animate-pulse rounded-lg border bg-muted/40" aria-hidden="true" />
                        }
                    >
                        <MarkdownEditor
                            value={createForm.data.body}
                            onChange={(markdown) => createForm.setData('body', markdown)}
                        />
                    </Suspense>
                    {createForm.errors.body ? (
                        <p className="text-sm text-destructive">{createForm.errors.body}</p>
                    ) : null}
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="new-knowledge-project">{t('issues.fields.project')}</Label>
                        {projectSelect(createForm, 'new-knowledge')}
                    </div>
                    <KnowledgeParentPicker
                        value={createForm.data.parent_id}
                        onChange={(id) => createForm.setData('parent_id', id)}
                        currentTitle={parentTitle(createForm.data.parent_id)}
                        inputId="new-knowledge-parent"
                    />
                </div>
            </FormDialog>
        </AppLayout>
    );
}

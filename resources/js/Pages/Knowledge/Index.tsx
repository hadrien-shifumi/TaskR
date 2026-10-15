import AppLayout from '@/layouts/app-layout';
import { Banner } from '@/components/banner';
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
import { BookOpen, Pencil, Plus, Search, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { PageSharedProps } from '@/types';

interface PageRef {
    id: string;
    title: string;
    parentId: string | null;
    projectId: string | null;
    updatedAt: string | null;
}

interface ProjectOption {
    id: string;
    name: string;
}

interface SelectedPage {
    id: string;
    title: string;
    body: string;
    parentId: string | null;
    projectId: string | null;
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

type PageProps = {
    pages: PageRef[];
    projects: ProjectOption[];
    selected: SelectedPage | null;
    query?: string;
    results?: SearchResult[];
    searchUnavailable?: boolean;
};

const NO_PROJECT = '__none__';
const NO_PARENT = '__none__';

type TreeNode = PageRef & { children: TreeNode[] };

function buildTree(pages: PageRef[]): TreeNode[] {
    const byId = new Map<string, TreeNode>();
    pages.forEach((page) => byId.set(page.id, { ...page, children: [] }));

    const roots: TreeNode[] = [];
    byId.forEach((node) => {
        if (node.parentId && byId.has(node.parentId)) {
            byId.get(node.parentId)!.children.push(node);
        } else {
            roots.push(node);
        }
    });

    return roots;
}

function formatDate(date: string | null): string {
    if (!date) return '—';
    return new Date(date).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

/** Maquette sidebar nav-item pattern applied to a tree row. */
function TreeRow({ node, depth, selectedId }: { node: TreeNode; depth: number; selectedId: string | null }) {
    return (
        <>
            <Link
                href={route('knowledge.show', node.id)}
                aria-current={node.id === selectedId ? 'page' : undefined}
                className="block truncate rounded-lg px-2.5 py-1.75 text-sm text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground aria-[current=page]:bg-secondary aria-[current=page]:font-medium aria-[current=page]:text-foreground"
                style={{ paddingLeft: `${0.75 + depth * 1}rem` }}
            >
                {node.title}
            </Link>
            {node.children.map((child) => (
                <TreeRow key={child.id} node={child} depth={depth + 1} selectedId={selectedId} />
            ))}
        </>
    );
}

const emptyForm = { title: '', body: '', project_id: '', parent_id: '' };

export default function KnowledgeIndex() {
    const { pages, projects, selected, query, results, searchUnavailable } = usePage<PageProps>().props;
    const { workspace } = usePage<PageSharedProps>().props;

    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState(false);
    const [deleting, setDeleting] = useState(false);

    const createForm = useForm({ ...emptyForm });
    const editForm = useForm({ ...emptyForm });
    const searchForm = useForm({ q: query ?? '' });

    const trees = buildTree(pages);
    const crossWorkspaceTrees = trees.filter((node) => !node.projectId);
    const selectedId = selected?.id ?? null;
    const projectName = (projectId: string | null): string | null =>
        projectId ? (projects.find((project) => project.id === projectId)?.name ?? null) : null;

    const submitCreate = (e: React.FormEvent) => {
        e.preventDefault();
        createForm.post(route('knowledge.store'), {
            onSuccess: () => {
                setCreating(false);
                createForm.reset();
            },
        });
    };

    const submitEdit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!selected) return;
        editForm.patch(route('knowledge.update', selected.id), {
            onSuccess: () => setEditing(false),
        });
    };

    const submitSearch = (e: React.FormEvent) => {
        e.preventDefault();
        if (!searchForm.data.q.trim()) {
            router.get(route('knowledge.index'), {}, { preserveState: true });
            return;
        }
        searchForm.get(route('knowledge.search'), { preserveState: true, only: ['query', 'results', 'searchUnavailable', 'pages', 'projects'] });
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
                <SelectValue>{form.data.project_id ? (projectName(form.data.project_id) ?? 'Project') : 'Cross-workspace'}</SelectValue>
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={NO_PROJECT}>Cross-workspace</SelectItem>
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
                <SelectValue>{form.data.parent_id ? (pages.find((page) => page.id === form.data.parent_id)?.title ?? 'Parent') : 'No parent'}</SelectValue>
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={NO_PARENT}>No parent</SelectItem>
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
            title="Knowledge"
            subtitle={workspace?.name}
            actions={
                <Button size="sm" onClick={() => setCreating(true)}>
                    <Plus />
                    New page
                </Button>
            }
        >
            <Head title="Knowledge" />

            <FlashBanner />

            <form onSubmit={submitSearch} className="mb-4 flex w-full max-w-md items-center gap-2">
                <div className="relative w-full">
                    <Search
                        className="pointer-events-none absolute top-2 left-2.5 size-3.5 text-muted-foreground"
                        aria-hidden
                    />
                    <Input
                        type="search"
                        value={searchForm.data.q}
                        onChange={(e) => searchForm.setData('q', e.target.value)}
                        placeholder="Search the knowledge base…"
                        aria-label="Semantic search"
                        className="h-7.5 rounded-md pl-8 text-[13px]"
                    />
                </div>
                <Button type="submit" variant="outline" size="sm" disabled={searchForm.processing}>
                    Search
                </Button>
            </form>

            {searchUnavailable ? (
                <Banner variant="warning" className="mb-4">
                    Semantic search is not configured for this workspace. Pages stay readable — ask an admin
                    to set KNOWLEDGE_EMBEDDINGS_BASE_URL and KNOWLEDGE_EMBEDDINGS_API_KEY.
                </Banner>
            ) : null}

            <div className="grid items-start gap-6 lg:grid-cols-[16rem_1fr]">
                <aside className="overflow-hidden rounded-xl border bg-card">
                    <div className="flex items-center gap-2.5 border-b px-4 py-3">
                        <h2 className="text-sm font-semibold">Pages</h2>
                        <span className="rounded-full bg-secondary px-2 py-0.5 font-mono text-[11px] text-muted-foreground tabular-nums">
                            {pages.length}
                        </span>
                    </div>
                    <nav aria-label="Knowledge tree" className="flex flex-col gap-3 px-3 py-3">
                        {crossWorkspaceTrees.length > 0 ? (
                            <section>
                                <h3 className="px-2 pb-1 font-mono text-[12px] tracking-[0.08em] uppercase text-muted-foreground">
                                    Cross-workspace
                                </h3>
                                {crossWorkspaceTrees.map((node) => (
                                    <TreeRow key={node.id} node={node} depth={0} selectedId={selectedId} />
                                ))}
                            </section>
                        ) : null}
                        {projects.map((project) => {
                            const projectTrees = trees.filter((node) => node.projectId === project.id);
                            if (projectTrees.length === 0) return null;
                            return (
                                <section key={project.id}>
                                    <h3 className="px-2 pb-1 font-mono text-[12px] tracking-[0.08em] uppercase text-muted-foreground">
                                        {project.name}
                                    </h3>
                                    {projectTrees.map((node) => (
                                        <TreeRow key={node.id} node={node} depth={0} selectedId={selectedId} />
                                    ))}
                                </section>
                            );
                        })}
                        {pages.length === 0 ? (
                            <p className="px-2 text-sm text-muted-foreground">No knowledge pages yet.</p>
                        ) : null}
                    </nav>
                </aside>

                <div className="min-w-0">
                    {results !== undefined ? (
                        <section aria-label="Search results">
                            <div className="mb-3 flex items-center gap-2">
                                <h2 className="text-base font-semibold">Results for “{query}”</h2>
                                <span className="rounded-full bg-secondary px-2 py-0.5 font-mono text-[11px] text-muted-foreground tabular-nums">
                                    {results.length}
                                </span>
                            </div>
                            {results.length === 0 ? (
                                <div className="rounded-xl border border-dashed px-4 py-8 text-center">
                                    <p className="font-medium">No matching pages.</p>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        Try different keywords or rephrase the question.
                                    </p>
                                </div>
                            ) : (
                                <ul className="grid gap-3">
                                    {results.map((result) => (
                                        <li key={result.id}>
                                            <Link
                                                href={route('knowledge.show', result.id)}
                                                className="block rounded-xl border bg-card p-4 transition-colors hover:border-primary/45"
                                            >
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="font-medium">{result.title}</span>
                                                    {result.projectId ? (
                                                        <Badge variant="secondary">
                                                            {projectName(result.projectId)}
                                                        </Badge>
                                                    ) : null}
                                                    <span className="ml-auto font-mono text-xs text-muted-foreground tabular-nums">
                                                        {result.score.toFixed(3)}
                                                    </span>
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
                        <article
                            className={`overflow-hidden rounded-xl border bg-card ${results !== undefined ? 'mt-6' : ''}`}
                        >
                            <header className="flex flex-wrap items-center gap-x-2.5 gap-y-2 border-b px-5 py-3.5">
                                <h2 className="text-base font-semibold tracking-tight">{selected.title}</h2>
                                {selected.projectId ? (
                                    <Badge variant="secondary">{projectName(selected.projectId)}</Badge>
                                ) : (
                                    <Badge variant="outline">Cross-workspace</Badge>
                                )}
                                <div className="ml-auto flex items-center gap-1">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => {
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
                                        Edit
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                        onClick={() => setDeleting(true)}
                                    >
                                        <Trash2 aria-hidden />
                                        Delete
                                    </Button>
                                </div>
                                <p className="w-full text-[13px] text-muted-foreground">
                                    {selected.lastEditor ? `Edited by ${selected.lastEditor}` : null}
                                    {selected.updatedAt ? ` · ${formatDate(selected.updatedAt)}` : null}
                                </p>
                            </header>
                            <div className="px-5 py-5">
                                <MarkdownView value={selected.body} />
                            </div>
                        </article>
                    ) : results === undefined ? (
                        <div className="rounded-xl border border-dashed px-4 py-10 text-center">
                            <BookOpen className="mx-auto size-5 text-muted-foreground" aria-hidden />
                            <p className="mt-2 font-medium">No page selected</p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Select a page in the tree, or search to get started.
                            </p>
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
                        <DialogTitle>Delete "{selected?.title}"?</DialogTitle>
                        <DialogDescription>
                            The page is removed from the knowledge base. This cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setDeleting(false)}>
                            Cancel
                        </Button>
                        <Button variant="destructive" data-test="confirm-delete-knowledge" onClick={remove}>
                            Delete
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <FormDialog
                open={creating}
                onOpenChange={setCreating}
                title="New knowledge page"
                description="Markdown body, optional parent page and project attachment."
                submitLabel="Create"
                processing={createForm.processing}
                contentClassName="max-h-[90vh] overflow-y-auto sm:max-w-2xl"
                onSubmit={submitCreate}
            >
                <div className="grid gap-2">
                    <Label htmlFor="new-knowledge-title">Title</Label>
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
                    <Label>Body</Label>
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
                        <Label htmlFor="new-knowledge-project">Project</Label>
                        {projectSelect(createForm, 'new-knowledge')}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="new-knowledge-parent">Parent page</Label>
                        {parentSelect(createForm, 'new-knowledge')}
                    </div>
                </div>
            </FormDialog>

            <FormDialog
                open={editing}
                onOpenChange={setEditing}
                title="Edit page"
                description="The page is re-indexed for semantic search after saving."
                submitLabel="Save"
                processing={editForm.processing}
                contentClassName="max-h-[90vh] overflow-y-auto sm:max-w-2xl"
                onSubmit={submitEdit}
            >
                <div className="grid gap-2">
                    <Label htmlFor="edit-knowledge-title">Title</Label>
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
                    <Label>Body</Label>
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
                        <Label htmlFor="edit-knowledge-project">Project</Label>
                        {projectSelect(editForm, 'edit-knowledge')}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="edit-knowledge-parent">Parent page</Label>
                        {parentSelect(editForm, 'edit-knowledge', selected?.id)}
                    </div>
                </div>
            </FormDialog>
        </AppLayout>
    );
}

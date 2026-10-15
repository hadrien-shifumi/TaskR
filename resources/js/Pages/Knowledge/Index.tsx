import AppLayout from '@/layouts/app-layout';
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
import { useState } from 'react';

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

interface PageProps {
    pages: PageRef[];
    projects: ProjectOption[];
    selected: SelectedPage | null;
    query?: string;
    results?: SearchResult[];
    searchUnavailable?: boolean;
}

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

function TreeRow({ node, depth, selectedId }: { node: TreeNode; depth: number; selectedId: string | null }) {
    return (
        <>
            <Link
                href={route('knowledge.show', node.id)}
                className={`block truncate rounded-md px-2 py-1 text-sm hover:bg-accent hover:text-accent-foreground ${
                    node.id === selectedId ? 'bg-muted font-medium' : ''
                }`}
                style={{ paddingLeft: `${0.5 + depth * 1}rem` }}
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
    const { flash } = usePage<{ flash?: { status?: string } }>().props;

    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState(false);

    const createForm = useForm({ ...emptyForm });
    const editForm = useForm({ ...emptyForm });
    const searchForm = useForm({ q: query ?? '' });

    const trees = buildTree(pages);
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
        if (!window.confirm(`Delete page "${selected.title}"?`)) return;
        router.delete(route('knowledge.destroy', selected.id));
    };

    const parentOptions = (excludeId?: string): PageRef[] =>
        pages.filter((page) => page.id !== excludeId);

    const projectSelect = (
        form: typeof createForm,
        idPrefix: string,
    ) => (
        <Select
            value={form.data.project_id || NO_PROJECT}
            onValueChange={(v) => form.setData('project_id', v === NO_PROJECT ? '' : v)}
        >
            <SelectTrigger id={`${idPrefix}-project`}>
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
            onValueChange={(v) => form.setData('parent_id', v === NO_PARENT ? '' : v)}
        >
            <SelectTrigger id={`${idPrefix}-parent`}>
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
        <AppLayout title="Knowledge">
            <Head title="Knowledge" />

            {flash?.status ? (
                <p className="mb-4 rounded-md border bg-muted/50 px-4 py-2 text-sm">{flash.status}</p>
            ) : null}

            <div className="mb-4 flex flex-wrap items-center gap-2">
                <form onSubmit={submitSearch} className="flex w-full max-w-md items-center gap-2">
                    <Input
                        value={searchForm.data.q}
                        onChange={(e) => searchForm.setData('q', e.target.value)}
                        placeholder="Search the knowledge base…"
                        aria-label="Semantic search"
                    />
                    <Button type="submit" variant="outline" disabled={searchForm.processing}>
                        Search
                    </Button>
                </form>
                <Button size="sm" onClick={() => setCreating(true)} className="ml-auto">
                    New page
                </Button>
            </div>

            {searchUnavailable ? (
                <p className="mb-4 rounded-md border bg-muted/50 px-4 py-2 text-sm text-muted-foreground">
                    Semantic search is not configured for this workspace. Pages stay readable — ask an admin to set
                    KNOWLEDGE_EMBEDDINGS_BASE_URL and KNOWLEDGE_EMBEDDINGS_API_KEY.
                </p>
            ) : null}

            <div className="grid gap-6 lg:grid-cols-[16rem_1fr]">
                <nav className="space-y-3" aria-label="Knowledge tree">
                    <section>
                        <h2 className="mb-1 px-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            Cross-workspace
                        </h2>
                        {trees.filter((node) => !node.projectId).map((node) => (
                            <TreeRow key={node.id} node={node} depth={0} selectedId={selected?.id ?? null} />
                        ))}
                    </section>
                    {projects.map((project) => {
                        const projectTrees = trees.filter((node) => node.projectId === project.id);
                        if (projectTrees.length === 0) return null;
                        return (
                            <section key={project.id}>
                                <h2 className="mb-1 px-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                    {project.name}
                                </h2>
                                {projectTrees.map((node) => (
                                    <TreeRow key={node.id} node={node} depth={0} selectedId={selected?.id ?? null} />
                                ))}
                            </section>
                        );
                    })}
                    {pages.length === 0 ? (
                        <p className="px-2 text-sm text-muted-foreground">No knowledge pages yet.</p>
                    ) : null}
                </nav>

                <div>
                    {results !== undefined ? (
                        <section aria-label="Search results">
                            <h2 className="mb-3 text-sm font-semibold">
                                Results for “{query}”
                            </h2>
                            {results.length === 0 ? (
                                <p className="text-sm text-muted-foreground">No matching pages.</p>
                            ) : (
                                <ul className="space-y-2">
                                    {results.map((result) => (
                                        <li key={result.id} className="rounded-md border p-3">
                                            <Link
                                                href={route('knowledge.show', result.id)}
                                                className="font-medium hover:underline"
                                            >
                                                {result.title}
                                            </Link>
                                            {result.projectId ? (
                                                <span className="ml-2 text-xs text-muted-foreground">
                                                    {projectName(result.projectId)}
                                                </span>
                                            ) : null}
                                            <p className="mt-1 text-sm text-muted-foreground">{result.excerpt}</p>
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                score {result.score.toFixed(3)}
                                            </p>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    ) : null}

                    {selected ? (
                        <article>
                            <div className="mb-4 flex flex-wrap items-center gap-2">
                                <h2 className="text-xl font-semibold">{selected.title}</h2>
                                <span className="text-sm text-muted-foreground">
                                    {selected.lastEditor ? `edited by ${selected.lastEditor}` : null}
                                    {selected.updatedAt ? ` · ${new Date(selected.updatedAt).toLocaleString()}` : ''}
                                </span>
                                <div className="ml-auto flex gap-1">
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
                                        Edit
                                    </Button>
                                    <Button variant="ghost" size="sm" onClick={remove}>
                                        Delete
                                    </Button>
                                </div>
                            </div>
                            {selected.projectId ? (
                                <p className="mb-4 text-sm text-muted-foreground">
                                    Project: {projectName(selected.projectId)}
                                </p>
                            ) : null}
                            <MarkdownView value={selected.body} />
                        </article>
                    ) : results === undefined ? (
                        <p className="text-sm text-muted-foreground">
                            Select a page in the tree, or search to get started.
                        </p>
                    ) : null}
                </div>
            </div>

            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>New knowledge page</DialogTitle>
                        <DialogDescription>
                            Markdown body, optional parent page and project attachment.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submitCreate} className="grid gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="new-knowledge-title">Title</Label>
                            <Input
                                id="new-knowledge-title"
                                value={createForm.data.title}
                                onChange={(e) => createForm.setData('title', e.target.value)}
                                required
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
                        <DialogFooter>
                            <Button type="submit" disabled={createForm.processing}>
                                Create
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={editing}
                onOpenChange={(open) => {
                    if (!open) setEditing(false);
                }}
            >
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Edit page</DialogTitle>
                        <DialogDescription>
                            The page is re-indexed for semantic search after saving.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submitEdit} className="grid gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="edit-knowledge-title">Title</Label>
                            <Input
                                id="edit-knowledge-title"
                                value={editForm.data.title}
                                onChange={(e) => editForm.setData('title', e.target.value)}
                                required
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
                        <DialogFooter>
                            <Button type="submit" disabled={editForm.processing}>
                                Save
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

import AppLayout from '@/layouts/app-layout';
import { FlashBanner } from '@/components/flash-banner';
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
import { MarkdownView } from '@/components/ui/markdown-editor';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Eye, History, RotateCcw } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { PageSharedProps } from '@/types';
import { formatDate } from '@/lib/format-date';

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

interface PageDetail {
    id: string;
    title: string;
    body: string;
    parentId: string | null;
    projectId: string | null;
    lastEditor: string | null;
    updatedAt: string | null;
}

interface VersionItem {
    id: string;
    title: string;
    editor: string | null;
    createdAt: string | null;
}

interface VersionDetail {
    id: string;
    title: string;
    body: string;
    editor: string | null;
    createdAt: string | null;
}

type PageProps = {
    page: PageDetail;
    versions: VersionItem[];
    pages: PageRef[];
    projects: ProjectOption[];
};

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

/** Sidebar nav-item pattern applied to a tree row (same as Index). */
function TreeRow({ node, depth, selectedId }: { node: TreeNode; depth: number; selectedId: string }) {
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

export default function KnowledgeVersions() {
    const { page, versions, pages, projects } = usePage<PageProps>().props;
    const { workspace } = usePage<PageSharedProps>().props;
    const { t } = useTranslation();

    const [versionToRestore, setVersionToRestore] = useState<VersionItem | null>(null);
    const [versionToPreview, setVersionToPreview] = useState<VersionItem | null>(null);
    const [versionContent, setVersionContent] = useState<VersionDetail | null>(null);
    const [loadingVersion, setLoadingVersion] = useState(false);

    const trees = buildTree(pages);
    const crossWorkspaceTrees = trees.filter((node) => !node.projectId);

    const projectName = (projectId: string | null): string | null =>
        projectId ? (projects.find((project) => project.id === projectId)?.name ?? null) : null;

    const restoreVersion = () => {
        if (!versionToRestore) return;
        router.post(
            route('knowledge.versions.restore', { page: page.id, version: versionToRestore.id }),
            {},
            {
                onSuccess: () => setVersionToRestore(null),
            },
        );
    };

    const previewVersion = (version: VersionItem) => {
        setVersionToPreview(version);
        setVersionContent(null);
        setLoadingVersion(true);

        fetch(route('knowledge.versions.show', { page: page.id, version: version.id }), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
            .then((res) => {
                if (!res.ok) throw new Error('Failed to load version');
                return res.json();
            })
            .then((data: VersionDetail) => {
                setVersionContent(data);
                setLoadingVersion(false);
            })
            .catch(() => setLoadingVersion(false));
    };

    return (
        <AppLayout
            title={t('knowledge.versions.title')}
            subtitle={workspace?.name}
            actions={
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => router.get(route('knowledge.show', page.id))}
                >
                    <ArrowLeft aria-hidden />
                    {t('common.back')}
                </Button>
            }
        >
            <Head title={t('knowledge.versions.title')} />

            <FlashBanner />

            <div className="grid items-start gap-6 lg:grid-cols-[16rem_1fr]">
                <aside className="overflow-hidden rounded-xl border bg-card">
                    <div className="flex items-center gap-2.5 border-b px-4 py-3">
                        <h2 className="text-sm font-semibold">{t('knowledge.pages')}</h2>
                        <span className="rounded-full bg-secondary px-2 py-0.5 font-mono text-[11px] text-muted-foreground tabular-nums">
                            {pages.length}
                        </span>
                    </div>
                    <nav aria-label={t('knowledge.treeAria')} className="flex flex-col gap-3 px-3 py-3">
                        {crossWorkspaceTrees.length > 0 ? (
                            <section>
                                <h3 className="px-2 pb-1 font-mono text-[12px] tracking-[0.08em] uppercase text-muted-foreground">
                                    {t('knowledge.crossWorkspace')}
                                </h3>
                                {crossWorkspaceTrees.map((node) => (
                                    <TreeRow key={node.id} node={node} depth={0} selectedId={page.id} />
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
                                        <TreeRow key={node.id} node={node} depth={0} selectedId={page.id} />
                                    ))}
                                </section>
                            );
                        })}
                        {pages.length === 0 ? (
                            <p className="px-2 text-sm text-muted-foreground">{t('knowledge.noPages')}</p>
                        ) : null}
                    </nav>
                </aside>

                <div className="min-w-0">
                    <article className="overflow-hidden rounded-xl border bg-card">
                        <header className="flex flex-wrap items-center gap-x-2.5 gap-y-2 border-b px-5 py-3.5">
                            <History className="size-4.5 text-muted-foreground" aria-hidden />
                            <h2 className="text-base font-semibold tracking-tight">{page.title}</h2>
                            {page.projectId ? (
                                <Badge variant="secondary">{projectName(page.projectId)}</Badge>
                            ) : (
                                <Badge variant="outline">{t('knowledge.crossWorkspace')}</Badge>
                            )}
                            <span className="ml-auto rounded-full bg-secondary px-2 py-0.5 font-mono text-[11px] text-muted-foreground tabular-nums">
                                {versions.length}
                            </span>
                        </header>
                        <div className="px-5 py-5">
                            <p className="mb-4 text-sm text-muted-foreground">
                                {t('knowledge.versions.description', { title: page.title })}
                            </p>

                            {versions.length === 0 ? (
                                <div className="rounded-xl border border-dashed px-4 py-8 text-center">
                                    <History className="mx-auto size-5 text-muted-foreground" aria-hidden />
                                    <p className="mt-2 font-medium">{t('knowledge.versions.noVersions')}</p>
                                </div>
                            ) : (
                                <div className="space-y-2">
                                    {versions.map((version) => (
                                        <div
                                            key={version.id}
                                            className="flex items-center justify-between gap-4 rounded-lg border px-4 py-3"
                                        >
                                            <div className="min-w-0">
                                                <p className="truncate font-medium">{version.title}</p>
                                                <p className="text-sm text-muted-foreground">
                                                    {version.editor
                                                        ? t('knowledge.versions.editedBy', { editor: version.editor })
                                                        : null}
                                                    {version.editor && version.createdAt ? ' · ' : null}
                                                    {version.createdAt ? formatDate(version.createdAt) : null}
                                                </p>
                                            </div>
                                            <div className="flex shrink-0 items-center gap-2">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => previewVersion(version)}
                                                >
                                                    <Eye aria-hidden />
                                                    {t('knowledge.versions.preview')}
                                                </Button>
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() => setVersionToRestore(version)}
                                                >
                                                    <RotateCcw aria-hidden />
                                                    {t('knowledge.versions.restore')}
                                                </Button>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    </article>
                </div>
            </div>

            <Dialog
                open={!!versionToRestore}
                onOpenChange={(open) => {
                    if (!open) setVersionToRestore(null);
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('knowledge.versions.restoreTitle')}</DialogTitle>
                        <DialogDescription>
                            {t('knowledge.versions.restoreDescription')}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setVersionToRestore(null)}>
                            {t('common.cancel')}
                        </Button>
                        <Button
                            data-test="confirm-restore-version"
                            onClick={restoreVersion}
                        >
                            <RotateCcw aria-hidden />
                            {t('knowledge.versions.restore')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={!!versionToPreview}
                onOpenChange={(open) => {
                    if (!open) {
                        setVersionToPreview(null);
                        setVersionContent(null);
                    }
                }}
            >
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                    <DialogHeader>
                        <DialogTitle>
                            {versionContent?.title ?? versionToPreview?.title}
                        </DialogTitle>
                        <DialogDescription>
                            {versionContent?.editor
                                ? t('knowledge.versions.editedBy', { editor: versionContent.editor })
                                : null}
                            {versionContent?.editor && versionContent?.createdAt ? ' · ' : null}
                            {versionContent?.createdAt ? formatDate(versionContent.createdAt) : null}
                        </DialogDescription>
                    </DialogHeader>
                    {loadingVersion ? (
                        <p className="py-8 text-center text-sm text-muted-foreground">
                            {t('common.loading')}
                        </p>
                    ) : versionContent ? (
                        <div className="rounded-lg border px-5 py-5">
                            <MarkdownView value={versionContent.body} />
                        </div>
                    ) : null}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

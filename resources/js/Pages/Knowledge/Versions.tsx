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
import { Head, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Eye, History, RotateCcw } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { PageSharedProps } from '@/types';
import { formatDate } from '@/lib/format-date';
import { KnowledgeSidebar, type PageRef, type ProjectOption } from './components/knowledge-sidebar';

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

export default function KnowledgeVersions() {
    const { page, versions, pages, projects } = usePage<PageProps>().props;
    const { workspace } = usePage<PageSharedProps>().props;
    const { t } = useTranslation();

    const [versionToRestore, setVersionToRestore] = useState<VersionItem | null>(null);
    const [versionToPreview, setVersionToPreview] = useState<VersionItem | null>(null);
    const [versionContent, setVersionContent] = useState<VersionDetail | null>(null);
    const [loadingVersion, setLoadingVersion] = useState(false);

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
                <KnowledgeSidebar pages={pages} projects={projects} selectedId={page.id} />

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

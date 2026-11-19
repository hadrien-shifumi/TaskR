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
import { MarkdownView } from '@/components/ui/markdown-view';
import { VersionDiff, type DiffSide } from '@/components/version-diff';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Head, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Eye, GitCompareArrows, History, RotateCcw } from 'lucide-react';
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

    // Version comparison: two picks (defaults = the two most recent), the
    // unified diff renders in the same dialog once both bodies arrived.
    const [compareOpen, setCompareOpen] = useState(false);
    const [fromVersionId, setFromVersionId] = useState<string | null>(null);
    const [toVersionId, setToVersionId] = useState<string | null>(null);
    const [comparison, setComparison] = useState<{ from: DiffSide; to: DiffSide } | null>(null);
    const [loadingComparison, setLoadingComparison] = useState(false);

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

    const fetchComparison = (fromId: string, toId: string) => {
        setComparison(null);
        setLoadingComparison(true);

        fetch(route('knowledge.versions.compare', { page: page.id, from: fromId, to: toId }), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
            .then((res) => {
                if (!res.ok) throw new Error('Failed to load comparison');
                return res.json();
            })
            .then((data: { from: DiffSide; to: DiffSide }) => {
                setComparison(data);
                setLoadingComparison(false);
            })
            .catch(() => setLoadingComparison(false));
    };

    /** Opens the compare dialog: the clicked version against the one just before it. */
    const openCompare = (version: VersionItem) => {
        const index = versions.findIndex((candidate) => candidate.id === version.id);
        const previous = versions[index + 1] ?? version;

        setFromVersionId(previous.id);
        setToVersionId(version.id);
        setComparison(null);
        setCompareOpen(true);
        fetchComparison(previous.id, version.id);
    };

    const changeComparison = (side: 'from' | 'to', versionId: string) => {
        const nextFrom = side === 'from' ? versionId : fromVersionId;
        const nextTo = side === 'to' ? versionId : toVersionId;

        if (!nextFrom || !nextTo) {
            return;
        }

        if (side === 'from') {
            setFromVersionId(versionId);
        } else {
            setToVersionId(versionId);
        }
        fetchComparison(nextFrom, nextTo);
    };

    const versionOptionLabel = (version: VersionItem): string =>
        version.createdAt ? `${version.title} · ${formatDate(version.createdAt)}` : version.title;

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
                <KnowledgeSidebar
                    pages={pages}
                    projects={projects}
                    selectedId={page.id}
                    projectFilter={page.projectId ?? 'transverse'}
                />

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
                                                    disabled={versions.length < 2}
                                                    onClick={() => openCompare(version)}
                                                >
                                                    <GitCompareArrows aria-hidden />
                                                    {t('knowledge.versions.compare')}
                                                </Button>
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
                open={compareOpen}
                onOpenChange={(open) => {
                    if (!open) {
                        setCompareOpen(false);
                        setComparison(null);
                    }
                }}
            >
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                    <DialogHeader>
                        <DialogTitle>{t('knowledge.versions.compareTitle')}</DialogTitle>
                        <DialogDescription>
                            {t('knowledge.versions.compareDescription')}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <label className="text-sm font-medium" htmlFor="compare-from-version">
                                {t('knowledge.versions.fromLabel')}
                            </label>
                            <Select value={fromVersionId ?? ''} onValueChange={(value) => {
                                if (value !== null) changeComparison('from', value);
                            }}>
                                <SelectTrigger id="compare-from-version" className="w-full">
                                    <SelectValue>
                                        {versions.find((version) => version.id === fromVersionId)
                                            ? versionOptionLabel(versions.find((version) => version.id === fromVersionId)!)
                                            : t('common.loading')}
                                    </SelectValue>
                                </SelectTrigger>
                                <SelectContent>
                                    {versions.map((version) => (
                                        <SelectItem key={version.id} value={version.id}>
                                            {versionOptionLabel(version)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid gap-1.5">
                            <label className="text-sm font-medium" htmlFor="compare-to-version">
                                {t('knowledge.versions.toLabel')}
                            </label>
                            <Select value={toVersionId ?? ''} onValueChange={(value) => {
                                if (value !== null) changeComparison('to', value);
                            }}>
                                <SelectTrigger id="compare-to-version" className="w-full">
                                    <SelectValue>
                                        {versions.find((version) => version.id === toVersionId)
                                            ? versionOptionLabel(versions.find((version) => version.id === toVersionId)!)
                                            : t('common.loading')}
                                    </SelectValue>
                                </SelectTrigger>
                                <SelectContent>
                                    {versions.map((version) => (
                                        <SelectItem key={version.id} value={version.id}>
                                            {versionOptionLabel(version)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    {loadingComparison ? (
                        <p className="py-8 text-center text-sm text-muted-foreground">
                            {t('common.loading')}
                        </p>
                    ) : comparison ? (
                        <VersionDiff from={comparison.from} to={comparison.to} />
                    ) : null}
                </DialogContent>
            </Dialog>

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

import { Banner } from '@/components/banner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { router } from '@inertiajs/react';
import { Globe } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { IssueDetail } from '../types';

type ProjectOption = { id: string; name: string };

/** Row of the `issues.knowledge-search` JSON payload. */
type KnowledgeSearchResult = {
    id: string;
    title: string;
    excerpt: string;
    score: number;
    projectId: string | null;
};

type KnowledgeSearchResponse = {
    results?: KnowledgeSearchResult[];
    searchUnavailable?: boolean;
};

type LinkKnowledgeDialogProps = {
    issue: IssueDetail;
    /** Nameable projects of the workspace (guest perimeter included) for the badges. */
    projects: ProjectOption[];
    open: boolean;
    onClose: () => void;
};

/**
 * Project badge of a knowledge page row: the project name when the page
 * belongs to one, the transverse marker otherwise. Shared with the sidebar.
 */
export function KnowledgeProjectBadge({
    projectId,
    name,
}: {
    projectId: string | null;
    name: string | null;
}) {
    const { t } = useTranslation();

    if (projectId === null) {
        return (
            <Badge variant="outline" className="gap-1">
                <Globe className="size-3" aria-hidden />
                {t('knowledge.crossWorkspace')}
            </Badge>
        );
    }

    if (name === null) return null;

    return <Badge variant="secondary">{name}</Badge>;
}

/**
 * Maquette dlg-link-knowledge: semantic search over the workspace pages
 * (debounced 400 ms, plain JSON endpoint — not an Inertia partial), with
 * checkboxes pre-checked for the linked pages. Submit syncs the whole set
 * (`page_ids`); a 422 refusal (out-of-perimeter or trashed page) surfaces
 * as a banner-danger inside the dialog. Trashed pages cannot stay linked:
 * the server forbids their ids in the payload, so their checkbox is
 * disabled and saving detaches them.
 */
export function LinkKnowledgeDialog({ issue, projects, open, onClose }: LinkKnowledgeDialogProps) {
    return (
        <Dialog
            open={open}
            onOpenChange={(o) => {
                if (!o) onClose();
            }}
        >
            {open ? (
                <LinkKnowledgeForm issue={issue} projects={projects} onClose={onClose} />
            ) : null}
        </Dialog>
    );
}

function LinkKnowledgeForm({
    issue,
    projects,
    onClose,
}: {
    issue: IssueDetail;
    projects: ProjectOption[];
    onClose: () => void;
}) {
    const { t } = useTranslation();
    // Trashed pages cannot be re-submitted (422): only live pages seed the
    // selection; the trashed ones render checked but disabled.
    const [selectedIds, setSelectedIds] = useState<string[]>(
        issue.knowledgePages.filter((page) => !page.trashed).map((page) => page.id),
    );
    const [search, setSearch] = useState('');
    const [results, setResults] = useState<KnowledgeSearchResult[]>([]);
    const [searching, setSearching] = useState(false);
    const [searchUnavailable, setSearchUnavailable] = useState(false);
    const [searchFailed, setSearchFailed] = useState(false);
    const [linkError, setLinkError] = useState<string | null>(null);
    /** Sequence guard: stale responses of a superseded query are dropped. */
    const latestRequest = useRef(0);

    const projectName = (projectId: string | null): string | null =>
        projectId ? (projects.find((project) => project.id === projectId)?.name ?? null) : null;

    // Debounced semantic search: fires 400 ms after the last keystroke,
    // mirroring Knowledge/Index. Emptying the box clears the results. The
    // endpoint answers plain JSON, so this is a fetch — not an Inertia
    // partial reload.
    useEffect(() => {
        const query = search.trim();
        const timer = setTimeout(() => {
            if (query === '') {
                setResults([]);
                setSearchUnavailable(false);
                setSearchFailed(false);
                setSearching(false);
                return;
            }
            const requestId = ++latestRequest.current;
            setSearching(true);
            fetch(route('issues.knowledge-search', { issue: issue.id, q: query }), {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            })
                .then((res) => {
                    if (!res.ok) throw new Error('Search failed');
                    return res.json() as Promise<KnowledgeSearchResponse>;
                })
                .then((data) => {
                    if (latestRequest.current !== requestId) return;
                    setResults(data.results ?? []);
                    setSearchUnavailable(data.searchUnavailable === true);
                    setSearchFailed(false);
                    setSearching(false);
                })
                .catch(() => {
                    if (latestRequest.current !== requestId) return;
                    setResults([]);
                    setSearchUnavailable(false);
                    setSearchFailed(true);
                    setSearching(false);
                });
        }, 400);
        return () => clearTimeout(timer);
    }, [search, issue.id]);

    const toggle = (pageId: string, checked: boolean) => {
        setLinkError(null);
        setSelectedIds((current) =>
            checked ? [...current, pageId] : current.filter((id) => id !== pageId),
        );
    };

    const save = (e: React.FormEvent) => {
        e.preventDefault();
        setLinkError(null);
        router.post(
            route('issues.knowledge', issue.id),
            { page_ids: selectedIds },
            {
                onSuccess: () => onClose(),
                onHttpException: (response) => {
                    setLinkError(
                        response.status === 422
                            ? t('issueShow.knowledgeLinkError')
                            : t('issueShow.knowledgeSetError'),
                    );
                    return false;
                },
            },
        );
    };

    const linkedIds = new Set(issue.knowledgePages.map((page) => page.id));
    // Pages already rendered in the linked group don't repeat in the results.
    const resultRows = results.filter((result) => !linkedIds.has(result.id));
    const hasTrashedLinked = issue.knowledgePages.some((page) => page.trashed);
    const listEmpty = issue.knowledgePages.length === 0 && resultRows.length === 0;

    return (
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{t('issueShow.knowledgeDialogTitle')}</DialogTitle>
                <DialogDescription>{t('issueShow.knowledgeDialogHint')}</DialogDescription>
            </DialogHeader>
            <form onSubmit={save} className="grid gap-4">
                <div className="grid gap-2">
                    <Label htmlFor="knowledge-link-search">{t('knowledge.searchAria')}</Label>
                    <Input
                        id="knowledge-link-search"
                        data-test="knowledge-search-input"
                        placeholder={t('knowledge.searchPlaceholder')}
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        autoComplete="off"
                    />
                </div>
                {searchUnavailable ? (
                    <Banner variant="warning" data-test="knowledge-search-unavailable">
                        {t('knowledge.searchUnavailable')}
                    </Banner>
                ) : null}
                {searchFailed ? (
                    <Banner variant="danger" role="alert" data-test="knowledge-search-error">
                        {t('issueShow.knowledgeSearchError')}
                    </Banner>
                ) : null}
                <div
                    data-test="knowledge-candidates"
                    className="grid max-h-64 gap-3 overflow-y-auto rounded-lg border border-border p-2"
                >
                    {issue.knowledgePages.length > 0 ? (
                        <div className="grid gap-1">
                            <p className="px-2 font-mono text-[11px] uppercase tracking-[0.08em] text-muted-foreground">
                                {t('issueShow.knowledgeLinkedPages')}
                            </p>
                            {issue.knowledgePages.map((page) => (
                                <Label
                                    key={page.id}
                                    className="flex cursor-pointer items-center gap-2.5 rounded-md px-2 py-1.5 text-sm font-normal hover:bg-muted"
                                >
                                    <Checkbox
                                        checked={selectedIds.includes(page.id)}
                                        disabled={page.trashed}
                                        onCheckedChange={(checked) => toggle(page.id, checked === true)}
                                        data-test="knowledge-linked-page"
                                    />
                                    <span className="min-w-0 truncate">{page.title}</span>
                                    <KnowledgeProjectBadge
                                        projectId={page.projectId}
                                        name={projectName(page.projectId)}
                                    />
                                    {page.trashed ? (
                                        <Badge
                                            variant="secondary"
                                            className="rounded-full bg-warning/10 text-warning"
                                        >
                                            {t('issueShow.knowledgeTrashed')}
                                        </Badge>
                                    ) : null}
                                </Label>
                            ))}
                            {hasTrashedLinked ? (
                                <p className="px-2 text-xs text-muted-foreground">
                                    {t('issueShow.knowledgeTrashedDetached')}
                                </p>
                            ) : null}
                        </div>
                    ) : null}
                    {resultRows.length > 0 ? (
                        <div className="grid gap-1">
                            <p className="px-2 font-mono text-[11px] uppercase tracking-[0.08em] text-muted-foreground">
                                {t('issueShow.knowledgeSearchResults')}
                            </p>
                            {resultRows.map((result) => (
                                <Label
                                    key={result.id}
                                    className="flex cursor-pointer items-start gap-2.5 rounded-md px-2 py-1.5 text-sm font-normal hover:bg-muted"
                                >
                                    <Checkbox
                                        checked={selectedIds.includes(result.id)}
                                        onCheckedChange={(checked) => toggle(result.id, checked === true)}
                                        data-test="knowledge-candidate"
                                        className="mt-0.5"
                                    />
                                    <span className="grid min-w-0 gap-0.5">
                                        <span className="flex min-w-0 flex-wrap items-center gap-1.5">
                                            <span className="min-w-0 truncate font-medium">
                                                {result.title}
                                            </span>
                                            <KnowledgeProjectBadge
                                                projectId={result.projectId}
                                                name={projectName(result.projectId)}
                                            />
                                            <Badge
                                                variant="outline"
                                                className="font-mono text-[11px] tabular-nums"
                                                title={result.score.toFixed(3)}
                                            >
                                                {Math.round(result.score * 100)}%
                                            </Badge>
                                        </span>
                                        <span className="line-clamp-2 text-xs text-muted-foreground">
                                            {result.excerpt}
                                        </span>
                                    </span>
                                </Label>
                            ))}
                        </div>
                    ) : null}
                    {listEmpty ? (
                        <p className="px-2 py-4 text-center text-sm text-muted-foreground">
                            {search.trim() === ''
                                ? t('issueShow.knowledgeDialogEmpty')
                                : searching
                                  ? t('common.loading')
                                  : t('knowledge.noMatchingPages')}
                        </p>
                    ) : null}
                </div>
                {linkError ? (
                    <Banner variant="danger" role="alert" data-test="knowledge-link-error">
                        <strong>{t('issueShow.knowledgeLinkRefused')}</strong> {linkError}
                    </Banner>
                ) : null}
                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button type="submit" data-test="knowledge-submit">
                        {t('common.save')}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}

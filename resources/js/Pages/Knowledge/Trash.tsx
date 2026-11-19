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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Input } from '@/components/ui/input';
import { Head, router, usePage } from '@inertiajs/react';
import { AlertTriangle, RotateCcw, Search, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { formatDate } from '@/lib/format-date';

interface TrashedPage {
    id: string;
    title: string;
    deletedAt: string | null;
    deletedBy: string | null;
    deletedReason: string | null;
    projectId: string | null;
    projectName: string | null;
}

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

type PageProps = {
    trashedPages: TrashedPage[];
    pages: PageRef[];
    projects: ProjectOption[];
};

/**
 * Mirror of PurgeKnowledgeTrash::RETENTION_DAYS (30 days): the purge date
 * shown per row is deletedAt plus this retention. Keep both in sync —
 * the command purges what this column promises.
 */
const TRASH_RETENTION_DAYS = 30;

const DAY_MS = 86_400_000;

/** Days left before the automatic purge, floored at 0. */
function daysBeforePurge(deletedAt: string | null): number {
    if (!deletedAt) {
        return 0;
    }
    const purgeAt = new Date(deletedAt).getTime() + TRASH_RETENTION_DAYS * DAY_MS;

    return Math.max(0, Math.ceil((purgeAt - Date.now()) / DAY_MS));
}

export default function KnowledgeTrash() {
    const { trashedPages } = usePage<PageProps>().props;
    const { t } = useTranslation();

    const [search, setSearch] = useState('');
    const [pageToRestore, setPageToRestore] = useState<TrashedPage | null>(null);
    const [pageToDelete, setPageToDelete] = useState<TrashedPage | null>(null);

    // Client-side title filter: the retention is 30 days, the list stays short.
    const visiblePages = search.trim() === ''
        ? trashedPages
        : trashedPages.filter((page) => page.title.toLowerCase().includes(search.trim().toLowerCase()));

    const restore = (page: TrashedPage) => {
        router.post(route('knowledge.restore', page.id), {}, {
            onSuccess: () => setPageToRestore(null),
        });
    };

    const forceDelete = (page: TrashedPage) => {
        router.delete(route('knowledge.force-delete', page.id), {
            onSuccess: () => setPageToDelete(null),
        });
    };

    const reasonBadge = (reason: string | null) => {
        if (reason === 'cascade_project') {
            return <Badge variant="secondary">{t('knowledge.trash.reasonCascade')}</Badge>;
        }
        return <Badge variant="outline">{t('knowledge.trash.reasonManual')}</Badge>;
    };

    return (
        <AppLayout
            title={
                <span className="flex items-center gap-2.5">
                    <Trash2 className="size-4.5" aria-hidden />
                    {t('knowledge.trash.title')}
                    <span className="rounded-full bg-secondary px-2 py-0.5 font-mono text-[11px] text-muted-foreground tabular-nums">
                        {trashedPages.length}
                    </span>
                </span>
            }
        >
            <Head title={t('knowledge.trash.title')} />

            <FlashBanner />

            <p className="mb-4 text-sm text-muted-foreground">
                {t('knowledge.trash.description')}
            </p>

            {trashedPages.length === 0 ? (
                <div className="flex flex-col items-center justify-center rounded-xl border border-dashed px-4 py-12 text-center">
                    <Trash2 className="size-8 text-muted-foreground" aria-hidden />
                    <p className="mt-3 font-medium">{t('knowledge.trash.empty')}</p>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {t('knowledge.trash.emptyHint')}
                    </p>
                </div>
            ) : (
                <>
                    <div className="relative mb-3">
                        <Search
                            className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden
                        />
                        <Input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder={t('knowledge.trash.searchPlaceholder')}
                            aria-label={t('knowledge.trash.searchPlaceholder')}
                            data-test="trash-search"
                            className="pl-9"
                        />
                    </div>
                    {visiblePages.length === 0 ? (
                        <div className="flex flex-col items-center justify-center rounded-xl border border-dashed px-4 py-12 text-center">
                            <Search className="size-8 text-muted-foreground/50" aria-hidden />
                            <p className="mt-3 font-medium">{t('knowledge.trash.searchNoResults')}</p>
                        </div>
                    ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('knowledge.trash.columnTitle')}</TableHead>
                                <TableHead>{t('knowledge.trash.columnProject')}</TableHead>
                                <TableHead>{t('knowledge.trash.columnDeletedBy')}</TableHead>
                                <TableHead>{t('knowledge.trash.columnDeletedAt')}</TableHead>
                                <TableHead>{t('knowledge.trash.columnPurge')}</TableHead>
                                <TableHead>{t('knowledge.trash.columnReason')}</TableHead>
                                <TableHead className="w-0 text-right">
                                    {t('knowledge.trash.columnActions')}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {visiblePages.map((page) => {
                                const days = daysBeforePurge(page.deletedAt);

                                return (
                                <TableRow key={page.id}>
                                    <TableCell className="font-medium">{page.title}</TableCell>
                                    <TableCell>
                                        {page.projectName ?? (
                                            <span className="text-muted-foreground">
                                                {t('knowledge.trash.transverse')}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell>{page.deletedBy ?? '—'}</TableCell>
                                    <TableCell className="tabular-nums">
                                        {formatDate(page.deletedAt)}
                                    </TableCell>
                                    <TableCell>
                                        {days > 0 ? (
                                            <span
                                                className="text-sm tabular-nums"
                                                data-test={`trash-purge-${page.id}`}
                                            >
                                                {days > 1
                                                    ? t('knowledge.trash.purgeInPlural', { count: days })
                                                    : t('knowledge.trash.purgeIn', { count: days })}
                                            </span>
                                        ) : (
                                            <Badge variant="destructive" data-test={`trash-purge-${page.id}`}>
                                                {t('knowledge.trash.purgeImminent')}
                                            </Badge>
                                        )}
                                    </TableCell>
                                    <TableCell>{reasonBadge(page.deletedReason)}</TableCell>
                                    <TableCell>
                                        <div className="flex justify-end gap-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                data-test={`trash-restore-${page.id}`}
                                                onClick={() => setPageToRestore(page)}
                                            >
                                                <RotateCcw aria-hidden />
                                                {t('knowledge.trash.restore')}
                                            </Button>
                                            <Button
                                                variant="destructive"
                                                size="sm"
                                                data-test={`trash-force-delete-${page.id}`}
                                                onClick={() => setPageToDelete(page)}
                                            >
                                                <Trash2 aria-hidden />
                                                {t('knowledge.trash.forceDelete')}
                                            </Button>
                                        </div>
                                    </TableCell>
                                </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                    )}
                </>
            )}

            <Dialog
                open={!!pageToRestore}
                onOpenChange={(open) => {
                    if (!open) setPageToRestore(null);
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('knowledge.trash.restoreTitle')}</DialogTitle>
                        <DialogDescription>
                            {t('knowledge.trash.restoreDescription')}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setPageToRestore(null)}>
                            {t('common.cancel')}
                        </Button>
                        <Button
                            data-test="confirm-restore-knowledge"
                            onClick={() => pageToRestore && restore(pageToRestore)}
                        >
                            <RotateCcw aria-hidden />
                            {t('knowledge.trash.restore')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={!!pageToDelete}
                onOpenChange={(open) => {
                    if (!open) setPageToDelete(null);
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2 text-destructive">
                            <AlertTriangle className="size-5" aria-hidden />
                            {t('knowledge.trash.forceDeleteTitle')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('knowledge.trash.forceDeleteDescription')}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setPageToDelete(null)}>
                            {t('common.cancel')}
                        </Button>
                        <Button
                            variant="destructive"
                            data-test="confirm-force-delete-knowledge"
                            onClick={() => pageToDelete && forceDelete(pageToDelete)}
                        >
                            <Trash2 aria-hidden />
                            {t('knowledge.trash.forceDelete')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

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
import { Head, router, usePage } from '@inertiajs/react';
import { AlertTriangle, RotateCcw, Trash2 } from 'lucide-react';
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

export default function KnowledgeTrash() {
    const { trashedPages } = usePage<PageProps>().props;
    const { t } = useTranslation();

    const [pageToRestore, setPageToRestore] = useState<TrashedPage | null>(null);
    const [pageToDelete, setPageToDelete] = useState<TrashedPage | null>(null);

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
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{t('knowledge.trash.columnTitle')}</TableHead>
                            <TableHead>{t('knowledge.trash.columnProject')}</TableHead>
                            <TableHead>{t('knowledge.trash.columnDeletedBy')}</TableHead>
                            <TableHead>{t('knowledge.trash.columnDeletedAt')}</TableHead>
                            <TableHead>{t('knowledge.trash.columnReason')}</TableHead>
                            <TableHead className="w-0 text-right">
                                {t('knowledge.trash.columnActions')}
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {trashedPages.map((page) => (
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
                        ))}
                    </TableBody>
                </Table>
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

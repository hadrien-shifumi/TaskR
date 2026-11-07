import { FormDialog } from '@/components/form-dialog';
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
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { PageSharedProps } from '@/types';
import { router, usePage, useForm } from '@inertiajs/react';
import { BookmarkPlus, ChevronDown, MoreHorizontal, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { SavedViewOption } from '../types';

type SavedViewsPickerProps = {
    /** Workspace views, alphabetical — one click applies one. */
    views: SavedViewOption[];
    /** Current URL filters — the save dialog posts them as the definition. */
    filters: Record<string, string | string[]>;
};

/**
 * Saved-views selector of the issues page: a flat alphabetical list of the
 * workspace's shared views, applied in one click, the "save this view"
 * button that names the current filters and sort as a new shared view, and
 * per-view management — rename (Owner only) and delete (Owner or creator)
 * behind a command shown only where the signed-in member may act.
 */
export function SavedViewsPicker({ views, filters }: SavedViewsPickerProps) {
    const { t } = useTranslation();
    const { workspace, auth } = usePage<PageSharedProps>().props;
    const [naming, setNaming] = useState(false);
    const [renaming, setRenaming] = useState<SavedViewOption | null>(null);
    const [deleting, setDeleting] = useState<SavedViewOption | null>(null);
    const form = useForm({ name: '' });
    const renameForm = useForm({ name: '' });

    const isOwner = workspace?.role === 'owner';
    const userId = auth.user?.id;
    const canDelete = (view: SavedViewOption) => isOwner || view.createdBy === userId;

    /** Applies a view: the stored filters and sort replace the current
     *  ones, back to the first page; the list/kanban mode stays as it is. */
    const applyView = (view: SavedViewOption) => {
        const next: Record<string, string | string[]> = { ...view.filters };
        if (view.sort !== null) {
            next.sort = view.sort;
        }
        if (view.dir !== null) {
            next.dir = view.dir;
        }
        const mode = filters.view;
        if (typeof mode === 'string' && mode !== '') {
            next.view = mode;
        }
        router.get(route('issues.index'), next, {
            preserveState: true,
            // Applying a view only refetches the data area, not the shell.
            only: mode === 'kanban'
                ? ['projectBoards', 'filters']
                : ['issues', 'total', 'totalUnfiltered', 'page', 'perPage', 'filters'],
        });
    };

    /** Saves the current configuration: every URL filter except the display
     *  mode and the page number — the server sanitizes the rest silently. */
    const saveView = (e: React.FormEvent) => {
        e.preventDefault();
        const { view: _view, page: _page, ...definition } = filters;
        form.transform((data) => ({
            name: data.name,
            ...definition,
        }));
        form.post(route('views.store'), {
            onSuccess: () => {
                setNaming(false);
                form.reset();
            },
        });
    };

    /** Renames a view — the definition itself never changes. */
    const renameView = (e: React.FormEvent) => {
        e.preventDefault();
        if (!renaming) return;
        renameForm.patch(route('views.update', renaming.id), {
            onSuccess: () => setRenaming(null),
        });
    };

    const confirmDelete = () => {
        if (!deleting) return;
        router.delete(route('views.destroy', deleting.id), {
            onSuccess: () => setDeleting(null),
        });
    };

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger
                    render={
                        <Button
                            variant="outline"
                            size="sm"
                            className="h-7.5 w-32 justify-between text-[13px]"
                            data-test="saved-views-picker"
                            aria-label={t('issuesPage.savedViews.aria')}
                        />
                    }
                >
                    {t('issuesPage.savedViews.label')}
                    <ChevronDown aria-hidden />
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" className="w-64">
                    {views.length === 0 ? (
                        <p
                            data-test="saved-views-empty"
                            className="px-1.5 py-1 text-xs text-muted-foreground"
                        >
                            {t('issuesPage.savedViews.empty')}
                        </p>
                    ) : (
                        views.map((view) => (
                            <div key={view.id} className="flex items-center gap-1 pr-1">
                                <DropdownMenuItem
                                    className="flex-1"
                                    data-test="saved-view-option"
                                    onClick={() => applyView(view)}
                                >
                                    <span className="flex flex-col items-start gap-0.5">
                                        <span className="font-medium">{view.name}</span>
                                        {view.summary ? (
                                            <span className="text-xs whitespace-normal text-muted-foreground">
                                                {view.summary}
                                            </span>
                                        ) : null}
                                    </span>
                                </DropdownMenuItem>
                                {canDelete(view) ? (
                                    <DropdownMenuSub>
                                        <DropdownMenuSubTrigger
                                            data-test="saved-view-manage"
                                            aria-label={t('issuesPage.savedViews.manageAria', { name: view.name })}
                                            className="grid size-6.5 shrink-0 place-items-center text-muted-foreground"
                                        >
                                            <MoreHorizontal className="size-4" aria-hidden />
                                        </DropdownMenuSubTrigger>
                                        <DropdownMenuSubContent>
                                            <DropdownMenuGroup>
                                                {isOwner ? (
                                                    <DropdownMenuItem
                                                        data-test="saved-view-rename"
                                                        onClick={() => {
                                                            setRenaming(view);
                                                            renameForm.setData('name', view.name);
                                                        }}
                                                    >
                                                        <Pencil aria-hidden />
                                                        {t('issuesPage.savedViews.rename')}
                                                    </DropdownMenuItem>
                                                ) : null}
                                                {isOwner ? <DropdownMenuSeparator /> : null}
                                                <DropdownMenuItem
                                                    variant="destructive"
                                                    data-test="saved-view-delete"
                                                    onClick={() => setDeleting(view)}
                                                >
                                                    <Trash2 aria-hidden />
                                                    {t('common.delete')}
                                                </DropdownMenuItem>
                                            </DropdownMenuGroup>
                                        </DropdownMenuSubContent>
                                    </DropdownMenuSub>
                                ) : null}
                            </div>
                        ))
                    )}
                </DropdownMenuContent>
            </DropdownMenu>
            <Button
                variant="outline"
                size="sm"
                className="h-7.5 text-[13px]"
                onClick={() => setNaming(true)}
                data-test="save-view-button"
                aria-label={t('issuesPage.savedViews.saveAria')}
            >
                <BookmarkPlus aria-hidden />
                {t('issuesPage.savedViews.save')}
            </Button>

            <FormDialog
                open={naming}
                onOpenChange={(open) => {
                    if (!open) setNaming(false);
                }}
                title={t('issuesPage.savedViews.save')}
                description={t('issuesPage.savedViews.dialogDescription')}
                submitLabel={t('common.save')}
                processing={form.processing}
                onSubmit={saveView}
            >
                <div className="grid gap-2">
                    <Label htmlFor="saved-view-name">{t('common.name')}</Label>
                    <Input
                        id="saved-view-name"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        required
                        maxLength={255}
                        aria-invalid={form.errors.name ? true : undefined}
                        data-test="save-view-name"
                    />
                    {form.errors.name ? (
                        <p className="text-sm text-destructive">{form.errors.name}</p>
                    ) : null}
                </div>
            </FormDialog>

            <FormDialog
                open={renaming !== null}
                onOpenChange={(open) => {
                    if (!open) setRenaming(null);
                }}
                title={t('issuesPage.savedViews.renameTitle')}
                description={t('issuesPage.savedViews.renameDescription')}
                submitLabel={t('common.save')}
                processing={renameForm.processing}
                onSubmit={renameView}
            >
                <div className="grid gap-2">
                    <Label htmlFor="rename-view-name">{t('common.name')}</Label>
                    <Input
                        id="rename-view-name"
                        value={renameForm.data.name}
                        onChange={(e) => renameForm.setData('name', e.target.value)}
                        required
                        maxLength={255}
                        aria-invalid={renameForm.errors.name ? true : undefined}
                        data-test="rename-view-name"
                    />
                    {renameForm.errors.name ? (
                        <p className="text-sm text-destructive">{renameForm.errors.name}</p>
                    ) : null}
                </div>
            </FormDialog>

            <Dialog
                open={deleting !== null}
                onOpenChange={(open) => {
                    if (!open) setDeleting(null);
                }}
            >
                {deleting ? (
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>{t('issuesPage.savedViews.deleteTitle')}</DialogTitle>
                            <DialogDescription>
                                {t('issuesPage.savedViews.deleteDescription', { name: deleting.name })}
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter>
                            <Button variant="ghost" onClick={() => setDeleting(null)}>
                                {t('common.cancel')}
                            </Button>
                            <Button variant="destructive" data-test="confirm-delete-view" onClick={confirmDelete}>
                                {t('common.delete')}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                ) : null}
            </Dialog>
        </>
    );
}

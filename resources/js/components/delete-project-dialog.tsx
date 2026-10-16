import { Banner } from '@/components/banner';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { router } from '@inertiajs/react';
import { Archive } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { ProjectRef } from '@/types';

type DeleteProjectDialogProps = {
    /** Project being deleted, or null when the dialog is closed. */
    project: (ProjectRef & { health?: { totalIssues: number } | null }) | null;
    onClose: () => void;
};

/**
 * Deletion is attempted as-is; the action refuses projects containing
 * issues and the refusal surfaces here with the archive path offered.
 */
export function DeleteProjectDialog({ project, onClose }: DeleteProjectDialogProps) {
    const { t } = useTranslation();
    const [deleteRefusal, setDeleteRefusal] = useState<string | null>(null);

    useEffect(() => {
        setDeleteRefusal(null);
    }, [project]);

    const confirmDelete = () => {
        if (!project) return;
        router.delete(route('projects.destroy', project.id), {
            onHttpException: () => {
                setDeleteRefusal(
                    t('deleteProject.refusal', { name: project.name }),
                );
                return false;
            },
            onSuccess: () => {
                onClose();
            },
        });
    };

    return (
        <Dialog
            open={project !== null}
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            {project ? (
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('deleteProject.title', { name: project.name })}</DialogTitle>
                        <DialogDescription>
                            {project.health?.totalIssues
                                ? t('deleteProject.containsIssues')
                                : t('deleteProject.cannotUndo')}
                        </DialogDescription>
                    </DialogHeader>
                    {deleteRefusal ? (
                        <div className="grid gap-3">
                            <Banner variant="danger">{deleteRefusal}</Banner>
                            <Button
                                data-test="archive-instead"
                                variant="outline"
                                className="justify-start"
                                onClick={() => {
                                    router.post(route('projects.archive', project.id));
                                    onClose();
                                }}
                            >
                                <Archive className="size-4" />
                                {t('deleteProject.archiveInstead', { name: project.name })}
                            </Button>
                        </div>
                    ) : null}
                    <DialogFooter>
                        <Button variant="ghost" onClick={onClose}>
                            {t('common.cancel')}
                        </Button>
                        <Button
                            variant="destructive"
                            data-test="confirm-delete-project"
                            onClick={() => confirmDelete()}
                        >
                            {t('common.delete')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            ) : null}
        </Dialog>
    );
}

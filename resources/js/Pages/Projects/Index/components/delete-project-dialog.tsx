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
import { Archive, TriangleAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ProjectRow } from '../types';

type DeleteProjectDialogProps = {
    /** Project being deleted, or null when the dialog is closed. */
    project: ProjectRow | null;
    onClose: () => void;
};

/**
 * Deletion is attempted as-is; the action refuses projects containing
 * issues and the refusal surfaces here with the archive path offered.
 */
export function DeleteProjectDialog({ project, onClose }: DeleteProjectDialogProps) {
    const [deleteRefusal, setDeleteRefusal] = useState<string | null>(null);

    useEffect(() => {
        setDeleteRefusal(null);
    }, [project]);

    const confirmDelete = () => {
        if (!project) return;
        router.delete(route('projects.destroy', project.id), {
            onHttpException: () => {
                setDeleteRefusal(
                    `Deletion refused — "${project.name}" contains issues. Archive it instead: the project stays consultable.`,
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
                        <DialogTitle>Delete "{project.name}"?</DialogTitle>
                        <DialogDescription>
                            {project.health?.totalIssues
                                ? 'This project contains issues.'
                                : 'This cannot be undone.'}
                        </DialogDescription>
                    </DialogHeader>
                    {deleteRefusal ? (
                        <div className="grid gap-3">
                            <p className="flex items-start gap-2.5 rounded-lg border border-destructive/35 bg-destructive/10 px-3.5 py-3 text-sm text-destructive">
                                <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                                <span>{deleteRefusal}</span>
                            </p>
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
                                Archive "{project.name}" instead
                            </Button>
                        </div>
                    ) : null}
                    <DialogFooter>
                        <Button variant="ghost" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            data-test="confirm-delete-project"
                            onClick={() => confirmDelete()}
                        >
                            Delete
                        </Button>
                    </DialogFooter>
                </DialogContent>
            ) : null}
        </Dialog>
    );
}

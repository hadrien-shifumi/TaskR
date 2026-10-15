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
import { Archive, Info } from 'lucide-react';
import type { ProjectRef } from '@/types';

type ArchiveProjectDialogProps = {
    /** Project being archived, or null when the dialog is closed. */
    project: ProjectRef | null;
    onClose: () => void;
};

/**
 * Confirmation before archiving (maquette dlg-archive): archiving only
 * removes the project from the active list, nothing is deleted.
 */
export function ArchiveProjectDialog({ project, onClose }: ArchiveProjectDialogProps) {
    return (
        <Dialog
            open={project !== null}
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            {project ? (
                <DialogContent showCloseButton={false}>
                    <DialogHeader>
                        <DialogTitle>Archive "{project.name}"?</DialogTitle>
                        <DialogDescription>The project disappears from the active list.</DialogDescription>
                    </DialogHeader>
                    <p className="flex items-start gap-2.5 rounded-lg border border-info/30 bg-info/10 px-3.5 py-3 text-sm">
                        <Info className="mt-0.5 size-4 shrink-0 text-info" aria-hidden />
                        <span>
                            Issues stay readable and the project can be unarchived at any time. No data is
                            deleted.
                        </span>
                    </p>
                    <DialogFooter>
                        <Button variant="ghost" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button
                            data-test="confirm-archive-project"
                            onClick={() => {
                                router.post(route('projects.archive', project.id));
                                onClose();
                            }}
                        >
                            <Archive className="size-4" aria-hidden />
                            Archive
                        </Button>
                    </DialogFooter>
                </DialogContent>
            ) : null}
        </Dialog>
    );
}

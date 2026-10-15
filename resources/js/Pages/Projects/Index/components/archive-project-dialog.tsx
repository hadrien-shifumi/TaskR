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
                    <Banner variant="info">
                        Issues stay readable and the project can be unarchived at any time. No data is
                        deleted.
                    </Banner>
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

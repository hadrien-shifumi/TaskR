import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { router } from '@inertiajs/react';
import type { ProjectRow } from '@/types';

function formatDate(date: string | null): string {
    if (!date) return '—';
    return new Date(date).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

type ArchivedProjectsDialogProps = {
    open: boolean;
    projects: ProjectRow[];
    onClose: () => void;
};

/**
 * Maquette dlg-archived: large dialog listing the archived projects with
 * their issue count and archive date — each stays viewable and restorable.
 */
export function ArchivedProjectsDialog({ open, projects, onClose }: ArchivedProjectsDialogProps) {
    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) onClose();
            }}
        >
            <DialogContent className="sm:max-w-[620px]">
                <DialogHeader>
                    <DialogTitle>Archived projects</DialogTitle>
                    <DialogDescription>
                        Out of the active list — issues stay readable, unarchiving is possible.
                    </DialogDescription>
                </DialogHeader>
                {projects.length === 0 ? (
                    <p className="text-sm text-muted-foreground">No archived projects.</p>
                ) : (
                    <ul className="flex flex-col gap-3.5">
                        {projects.map((project) => {
                            const issues = project.health?.totalIssues ?? 0;

                            return (
                                <li
                                    key={project.id}
                                    className="flex items-center justify-between gap-3.5 rounded-lg border px-3.5 py-3.5"
                                >
                                    <div className="min-w-0">
                                        <p className="font-medium">{project.name}</p>
                                        <p className="text-xs text-muted-foreground">
                                            {`${issues} ${issues === 1 ? 'issue' : 'issues'} · archived on ${formatDate(project.archivedAt)}`}
                                        </p>
                                    </div>
                                    <div className="flex shrink-0 items-center gap-2">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => router.get(route('projects.health', project.id))}
                                        >
                                            View
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => router.post(route('projects.archive', project.id))}
                                        >
                                            Unarchive
                                        </Button>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </DialogContent>
        </Dialog>
    );
}

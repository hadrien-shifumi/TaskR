import { DeleteProjectDialog } from '@/components/delete-project-dialog';
import { ProjectFormDialog } from '@/components/project-form-dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { router } from '@inertiajs/react';
import { Archive, MoreHorizontal, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { ProjectRef } from '@/types';

type ProjectMenuProps = {
    project: ProjectRef;
    /** Issue count shown on the disabled delete entry. */
    issuesCount: number;
};

/** ⋯ project menu for the project pagebars: edit dialog, archive, delete dialog.
 * Delete is refused while the project still has issues (domain rule) so the
 * entry stays disabled with the count. */
export function ProjectMenu({ project, issuesCount }: ProjectMenuProps) {
    const [editing, setEditing] = useState(false);
    const [deleting, setDeleting] = useState(false);

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger
                    aria-label={`Actions for ${project.name}`}
                    className="grid size-7 place-items-center rounded-lg text-muted-foreground outline-none hover:bg-accent hover:text-foreground"
                >
                    <MoreHorizontal className="size-4" aria-hidden />
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuGroup>
                        <DropdownMenuLabel>{project.name}</DropdownMenuLabel>
                        <DropdownMenuItem onClick={() => setEditing(true)}>
                            <Pencil aria-hidden />
                            Edit…
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            onClick={() => router.post(route('projects.archive', project.id))}
                        >
                            <Archive aria-hidden />
                            Archive…
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            variant="destructive"
                            disabled={issuesCount > 0}
                            onClick={() => setDeleting(true)}
                        >
                            <Trash2 aria-hidden />
                            Delete…
                            {issuesCount > 0 ? ` (${issuesCount})` : ''}
                        </DropdownMenuItem>
                    </DropdownMenuGroup>
                </DropdownMenuContent>
            </DropdownMenu>

            <ProjectFormDialog open={editing} project={project} onClose={() => setEditing(false)} />
            <DeleteProjectDialog project={deleting ? project : null} onClose={() => setDeleting(false)} />
        </>
    );
}

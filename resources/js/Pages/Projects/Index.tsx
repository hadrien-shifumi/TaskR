import { FlashBanner } from '@/components/flash-banner';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Archive } from 'lucide-react';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { DeleteProjectDialog } from '@/components/delete-project-dialog';
import { ProjectFormDialog } from '@/components/project-form-dialog';
import { ProjectTableRow } from './Index/components/project-table-row';
import type { ProjectRow } from '@/types';

type PageProps = {
    projects: {
        active: ProjectRow[];
        archived: ProjectRow[];
    };
};

export default function Index() {
    const { projects } = usePage<PageProps>().props;
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState<ProjectRow | null>(null);
    const [deleting, setDeleting] = useState<ProjectRow | null>(null);
    const [showArchived, setShowArchived] = useState(false);

    return (
        <AppLayout
            title="Projects"
            subtitle="Active workspace only"
            actions={
                <>
                    {projects.archived.length > 0 ? (
                        <Button variant="outline" size="sm" onClick={() => setShowArchived(true)}>
                            <Archive className="size-3.5" aria-hidden />
                            Archived
                            <span className="font-mono opacity-70">{projects.archived.length}</span>
                        </Button>
                    ) : null}
                    <Button size="sm" onClick={() => setCreating(true)}>
                        New project
                    </Button>
                </>
            }
        >
            <Head title="Projects" />

            <FlashBanner />

            <p className="mb-4 text-sm text-muted-foreground">
                Projects of the active workspace only. Completion and projection are computed — never entered.
            </p>

            <div className="overflow-hidden rounded-xl border bg-card">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="w-[34%]">Project</TableHead>
                            <TableHead>Health</TableHead>
                            <TableHead className="w-[16%]">Completed</TableHead>
                            <TableHead>Target date</TableHead>
                            <TableHead className="font-mono">Issues</TableHead>
                            <TableHead className="w-10" aria-label="Actions" />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {projects.active.map((project) => (
                            <ProjectTableRow
                                key={project.id}
                                project={project}
                                onEdit={() => setEditing(project)}
                                onDelete={() => setDeleting(project)}
                            />
                        ))}
                        {projects.active.length === 0 ? (
                            <TableRow>
                                <TableCell colSpan={6} className="py-6 text-center text-muted-foreground">
                                    No active projects yet — create one to get started.
                                </TableCell>
                            </TableRow>
                        ) : null}
                    </TableBody>
                </Table>
            </div>

            <Dialog open={showArchived} onOpenChange={setShowArchived}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <Archive className="size-4" aria-hidden />
                            Archived projects
                        </DialogTitle>
                        <DialogDescription>
                            Archived projects stay readable — restore them to reopen the board and health views.
                        </DialogDescription>
                    </DialogHeader>
                    <ul className="divide-y rounded-lg border">
                        {projects.archived.map((project) => (
                            <li key={project.id} className="flex items-center gap-3 px-4 py-3">
                                <div className="min-w-0 flex-1">
                                    <p className="font-medium text-muted-foreground">{project.name}</p>
                                </div>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => router.get(route('projects.board', project.id))}
                                >
                                    Board
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => router.post(route('projects.archive', project.id))}
                                >
                                    Restore
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="text-destructive"
                                    onClick={() => {
                                        setShowArchived(false);
                                        setDeleting(project);
                                    }}
                                >
                                    Delete
                                </Button>
                            </li>
                        ))}
                    </ul>
                </DialogContent>
            </Dialog>

            <ProjectFormDialog
                open={creating || editing !== null}
                project={editing}
                onClose={() => {
                    setCreating(false);
                    setEditing(null);
                }}
            />

            <DeleteProjectDialog project={deleting} onClose={() => setDeleting(null)} />
        </AppLayout>
    );
}

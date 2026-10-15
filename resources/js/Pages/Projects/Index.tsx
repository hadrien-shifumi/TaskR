import { FlashBanner } from '@/components/flash-banner';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Archive } from 'lucide-react';
import { Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { DeleteProjectDialog } from '@/components/delete-project-dialog';
import { ProjectFormDialog } from '@/components/project-form-dialog';
import { ArchivedProjectsDialog } from './Index/components/archived-projects-dialog';
import { ArchiveProjectDialog } from './Index/components/archive-project-dialog';
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
    const [archiving, setArchiving] = useState<ProjectRow | null>(null);
    const [deleting, setDeleting] = useState<ProjectRow | null>(null);
    const [showArchived, setShowArchived] = useState(false);

    return (
        <AppLayout
            title="Projects"
            subtitle="Active workspace only"
            actions={
                <>
                    {projects.archived.length > 0 ? (
                        <Button
                            variant="outline"
                            size="sm"
                            data-test="open-archived-dialog"
                            onClick={() => setShowArchived(true)}
                        >
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
                                onArchive={() => setArchiving(project)}
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

            <ArchivedProjectsDialog
                open={showArchived}
                projects={projects.archived}
                onClose={() => setShowArchived(false)}
            />

            <ProjectFormDialog
                open={creating || editing !== null}
                project={editing}
                onClose={() => {
                    setCreating(false);
                    setEditing(null);
                }}
            />

            <ArchiveProjectDialog project={archiving} onClose={() => setArchiving(null)} />

            <DeleteProjectDialog project={deleting} onClose={() => setDeleting(null)} />
        </AppLayout>
    );
}

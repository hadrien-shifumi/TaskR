import { FlashBanner } from '@/components/flash-banner';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import { Archive } from 'lucide-react';
import { useState } from 'react';
import { DeleteProjectDialog } from './Index/components/delete-project-dialog';
import { ProjectFormDialog } from './Index/components/project-form-dialog';
import { ProjectListItem } from './Index/components/project-list-item';
import type { ProjectRow } from './Index/types';

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

    return (
        <AppLayout title="Projects">
            <Head title="Projects" />

            <FlashBanner />

            <div className="mb-4 flex items-center justify-between">
                <p className="text-sm text-muted-foreground">
                    Projects are simple containers. Statuses live at the workspace level.
                </p>
                <Button size="sm" onClick={() => setCreating(true)}>
                    New project
                </Button>
            </div>

            <ul className="divide-y overflow-hidden rounded-xl border bg-card">
                {projects.active.map((project) => (
                    <ProjectListItem key={project.id} project={project} onEdit={setEditing} />
                ))}
                {projects.active.length === 0 ? (
                    <li className="px-4 py-6 text-center text-sm text-muted-foreground">
                        No active projects yet.
                    </li>
                ) : null}
            </ul>

            {projects.archived.length > 0 ? (
                <div className="mt-8">
                    <h2 className="mb-2 flex items-center gap-1.5 text-sm font-semibold text-muted-foreground">
                        <Archive className="size-3.5" />
                        Archived
                    </h2>
                    <ul className="divide-y overflow-hidden rounded-xl border bg-card">
                        {projects.archived.map((project) => (
                            <li key={project.id} className="flex items-center gap-4 px-4 py-3">
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
                                    onClick={() => setDeleting(project)}
                                >
                                    Delete
                                </Button>
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}

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

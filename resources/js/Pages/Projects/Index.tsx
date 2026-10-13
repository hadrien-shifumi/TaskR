import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface ProjectRow {
    id: string;
    name: string;
    description: string | null;
    archivedAt: string | null;
}

type PageProps = {
    projects: {
        active: ProjectRow[];
        archived: ProjectRow[];
    };
};

export default function Index() {
    const { projects } = usePage<PageProps>().props;
    const { flash } = usePage<{ flash?: { status?: string } }>().props;
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState<ProjectRow | null>(null);

    const createForm = useForm({ name: '', description: '' });
    const editForm = useForm({ name: '', description: '' });

    const submitCreate = (e: React.FormEvent) => {
        e.preventDefault();
        createForm.post(route('projects.store'), {
            onSuccess: () => {
                setCreating(false);
                createForm.reset();
            },
        });
    };

    const submitEdit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!editing) return;
        editForm.patch(route('projects.update', editing.id), {
            onSuccess: () => setEditing(null),
        });
    };

    const toggleArchive = (project: ProjectRow) => {
        router.post(route('projects.archive', project.id));
    };

    const remove = (project: ProjectRow) => {
        if (!window.confirm(`Delete project "${project.name}"?`)) return;
        router.delete(route('projects.destroy', project.id));
    };

    return (
        <AppLayout title="Projects">
            <Head title="Projects" />

            {flash?.status ? (
                <p className="mb-4 rounded-md border bg-muted/50 px-4 py-2 text-sm">{flash.status}</p>
            ) : null}

            <div className="mb-4 flex items-center justify-between">
                <p className="text-sm text-muted-foreground">
                    Projects are simple containers. Statuses live at the workspace level.
                </p>
                <Button size="sm" onClick={() => setCreating(true)}>
                    New project
                </Button>
            </div>

            <ul className="divide-y rounded-md border">
                {projects.active.map((project) => (
                    <li key={project.id} className="flex items-center gap-4 px-4 py-3">
                        <div className="min-w-0 flex-1">
                            <p className="font-medium">{project.name}</p>
                            {project.description ? (
                                <p className="truncate text-sm text-muted-foreground">{project.description}</p>
                            ) : null}
                        </div>
                        <div className="flex shrink-0 gap-1">
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => {
                                    setEditing(project);
                                    editForm.setData({ name: project.name, description: project.description ?? '' });
                                }}
                            >
                                Edit
                            </Button>
                            <Button variant="ghost" size="sm" onClick={() => toggleArchive(project)}>
                                Archive
                            </Button>
                        </div>
                    </li>
                ))}
                {projects.active.length === 0 ? (
                    <li className="px-4 py-6 text-center text-sm text-muted-foreground">
                        No active projects yet.
                    </li>
                ) : null}
            </ul>

            {projects.archived.length > 0 ? (
                <div className="mt-8">
                    <h2 className="mb-2 text-sm font-semibold text-muted-foreground">Archived</h2>
                    <ul className="divide-y rounded-md border">
                        {projects.archived.map((project) => (
                            <li key={project.id} className="flex items-center gap-4 px-4 py-3">
                                <div className="min-w-0 flex-1">
                                    <p className="font-medium text-muted-foreground">{project.name}</p>
                                </div>
                                <Badge variant="outline">Archived</Badge>
                                <div className="flex shrink-0 gap-1">
                                    <Button variant="ghost" size="sm" onClick={() => toggleArchive(project)}>
                                        Restore
                                    </Button>
                                    <Button variant="ghost" size="sm" onClick={() => remove(project)}>
                                        Delete
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}

            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>New project</DialogTitle>
                        <DialogDescription>Create a project in this workspace.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submitCreate} className="grid gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="new-name">Name</Label>
                            <Input
                                id="new-name"
                                value={createForm.data.name}
                                onChange={(e) => createForm.setData('name', e.target.value)}
                                required
                            />
                            {createForm.errors.name ? (
                                <p className="text-sm text-destructive">{createForm.errors.name}</p>
                            ) : null}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="new-description">Description</Label>
                            <Textarea
                                id="new-description"
                                value={createForm.data.description}
                                onChange={(e) => createForm.setData('description', e.target.value)}
                            />
                        </div>
                        <DialogFooter>
                            <Button type="submit" disabled={createForm.processing}>
                                Create
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={editing !== null}
                onOpenChange={(open) => {
                    if (!open) setEditing(null);
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Edit project</DialogTitle>
                        <DialogDescription>Rename or update the description.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submitEdit} className="grid gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="edit-name">Name</Label>
                            <Input
                                id="edit-name"
                                value={editForm.data.name}
                                onChange={(e) => editForm.setData('name', e.target.value)}
                                required
                            />
                            {editForm.errors.name ? (
                                <p className="text-sm text-destructive">{editForm.errors.name}</p>
                            ) : null}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="edit-description">Description</Label>
                            <Textarea
                                id="edit-description"
                                value={editForm.data.description}
                                onChange={(e) => editForm.setData('description', e.target.value)}
                            />
                        </div>
                        <DialogFooter>
                            <Button type="submit" disabled={editForm.processing}>
                                Save
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

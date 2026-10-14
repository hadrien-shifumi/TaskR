import AppLayout from '@/layouts/app-layout';
import { HealthBadge } from '@/components/issue-badges';
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
import { Archive, TriangleAlert } from 'lucide-react';
import { useState } from 'react';

interface HealthData {
    issuesByCategory: { backlog: number; in_progress: number; done: number; cancelled: number };
    totalIssues: number;
    completionPercentage: number | null;
    overdueIssues: number;
    estimatedHours: number;
    loggedHours: number;
    consumptionRatio: number | null;
    expectedPercentage: number | null;
    status: string | null;
    targetDate: string | null;
}

interface ProjectRow {
    id: string;
    name: string;
    description: string | null;
    targetDate: string | null;
    archivedAt: string | null;
    health: HealthData | null;
}

type PageProps = {
    projects: {
        active: ProjectRow[];
        archived: ProjectRow[];
    };
};

function formatDate(date: string | null): string {
    if (!date) return '—';
    return new Date(date).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

export default function Index() {
    const { projects } = usePage<PageProps>().props;
    const { flash } = usePage<{ flash?: { status?: string } }>().props;
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState<ProjectRow | null>(null);
    const [deleting, setDeleting] = useState<ProjectRow | null>(null);
    const [deleteRefusal, setDeleteRefusal] = useState<string | null>(null);

    const createForm = useForm({ name: '', description: '', target_date: '' });
    const editForm = useForm({ name: '', description: '', target_date: '' });

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

    /**
     * Deletion is attempted as-is; the action refuses projects containing
     * issues and the refusal surfaces here with the archive path offered.
     */
    const confirmDelete = () => {
        if (!deleting) return;
        router.delete(route('projects.destroy', deleting.id), {
            onHttpException: () => {
                setDeleteRefusal(
                    `Deletion refused — "${deleting.name}" contains issues. Archive it instead: the project stays consultable.`,
                );
                return false;
            },
            onSuccess: () => {
                setDeleting(null);
                setDeleteRefusal(null);
            },
        });
    };

    return (
        <AppLayout title="Projects">
            <Head title="Projects" />

            {flash?.status ? (
                <p className="mb-4 rounded-lg border bg-muted/50 px-4 py-2.5 text-sm">{flash.status}</p>
            ) : null}

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
                    <li key={project.id} className="flex items-center gap-4 px-4 py-3">
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <p className="font-medium">{project.name}</p>
                                {project.health ? <HealthBadge status={project.health.status} /> : null}
                                {project.health && project.health.overdueIssues > 0 ? (
                                    <span className="font-mono text-xs text-destructive tabular-nums">
                                        {project.health.overdueIssues} overdue
                                    </span>
                                ) : null}
                            </div>
                            {project.description ? (
                                <p className="truncate text-sm text-muted-foreground">{project.description}</p>
                            ) : null}
                            <div className="mt-1.5 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                                <span className="font-mono tabular-nums">
                                    {project.health?.totalIssues ?? 0} issues
                                </span>
                                {project.targetDate ? <span>target: {formatDate(project.targetDate)}</span> : null}
                                {project.health && project.health.completionPercentage !== null ? (
                                    <span className="font-mono tabular-nums">
                                        {project.health.completionPercentage}% complete
                                    </span>
                                ) : null}
                            </div>
                        </div>
                        <div className="flex shrink-0 gap-1">
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => router.get(route('projects.health', project.id))}
                            >
                                Health
                            </Button>
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
                                onClick={() => {
                                    setEditing(project);
                                    editForm.setData({
                                        name: project.name,
                                        description: project.description ?? '',
                                        target_date: project.targetDate ?? '',
                                    });
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
                                <Button variant="ghost" size="sm" onClick={() => toggleArchive(project)}>
                                    Restore
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="text-destructive"
                                    onClick={() => {
                                        setDeleting(project);
                                        setDeleteRefusal(null);
                                    }}
                                >
                                    Delete
                                </Button>
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
                        <div className="grid gap-2">
                            <Label htmlFor="new-target-date">Target date</Label>
                            <Input
                                id="new-target-date"
                                type="date"
                                value={createForm.data.target_date}
                                onChange={(e) => createForm.setData('target_date', e.target.value)}
                            />
                            {createForm.errors.target_date ? (
                                <p className="text-sm text-destructive">{createForm.errors.target_date}</p>
                            ) : null}
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
                        <div className="grid gap-2">
                            <Label htmlFor="edit-target-date">Target date</Label>
                            <Input
                                id="edit-target-date"
                                type="date"
                                value={editForm.data.target_date}
                                onChange={(e) => editForm.setData('target_date', e.target.value)}
                            />
                            {editForm.errors.target_date ? (
                                <p className="text-sm text-destructive">{editForm.errors.target_date}</p>
                            ) : null}
                        </div>
                        <DialogFooter>
                            <Button type="submit" disabled={editForm.processing}>
                                Save
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={deleting !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setDeleting(null);
                        setDeleteRefusal(null);
                    }
                }}
            >
                {deleting ? (
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Delete "{deleting.name}"?</DialogTitle>
                            <DialogDescription>
                                {deleting.health?.totalIssues
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
                                        toggleArchive(deleting);
                                        setDeleting(null);
                                        setDeleteRefusal(null);
                                    }}
                                >
                                    <Archive className="size-4" />
                                    Archive "{deleting.name}" instead
                                </Button>
                            </div>
                        ) : null}
                        <DialogFooter>
                            <Button variant="ghost" onClick={() => setDeleting(null)}>
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
        </AppLayout>
    );
}

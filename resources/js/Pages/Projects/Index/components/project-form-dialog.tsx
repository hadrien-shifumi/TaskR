import { FormDialog } from '@/components/form-dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import type { ProjectRow } from '../types';

type ProjectFormDialogProps = {
    open: boolean;
    /** Project being edited, or null in create mode. */
    project: ProjectRow | null;
    onClose: () => void;
};

/** Create/edit dialog for a project; `project` selects the mode. */
export function ProjectFormDialog({ open, project, onClose }: ProjectFormDialogProps) {
    const form = useForm({ name: '', description: '', target_date: '' });

    // Seed at open: edit mode reuses the row's values, create mode resets.
    useEffect(() => {
        if (project) {
            form.setData({
                name: project.name,
                description: project.description ?? '',
                target_date: project.targetDate ?? '',
            });
        } else {
            form.reset();
        }
    }, [project]);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (project) {
            form.patch(route('projects.update', project.id), {
                onSuccess: () => onClose(),
            });
            return;
        }
        form.post(route('projects.store'), {
            onSuccess: () => {
                onClose();
                form.reset();
            },
        });
    };

    return (
        <FormDialog
            open={open}
            onOpenChange={(o) => {
                if (!o) onClose();
            }}
            title={project ? 'Edit project' : 'New project'}
            description={project ? 'Rename or update the description.' : 'Create a project in this workspace.'}
            submitLabel={project ? 'Save' : 'Create'}
            processing={form.processing}
            onSubmit={submit}
        >
            <div className="grid gap-2">
                <Label htmlFor={project ? 'edit-name' : 'new-name'}>Name</Label>
                <Input
                    id={project ? 'edit-name' : 'new-name'}
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    required
                />
                {form.errors.name ? (
                    <p className="text-sm text-destructive">{form.errors.name}</p>
                ) : null}
            </div>
            <div className="grid gap-2">
                <Label htmlFor={project ? 'edit-description' : 'new-description'}>Description</Label>
                <Textarea
                    id={project ? 'edit-description' : 'new-description'}
                    value={form.data.description}
                    onChange={(e) => form.setData('description', e.target.value)}
                />
            </div>
            <div className="grid gap-2">
                <Label htmlFor={project ? 'edit-target-date' : 'new-target-date'}>Target date</Label>
                <Input
                    id={project ? 'edit-target-date' : 'new-target-date'}
                    type="date"
                    value={form.data.target_date}
                    onChange={(e) => form.setData('target_date', e.target.value)}
                />
                {form.errors.target_date ? (
                    <p className="text-sm text-destructive">{form.errors.target_date}</p>
                ) : null}
            </div>
        </FormDialog>
    );
}

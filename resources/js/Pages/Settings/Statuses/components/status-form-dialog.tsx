import { FormDialog } from '@/components/form-dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import type { StatusRow } from '../types';

const CATEGORIES = [
    { value: 'backlog', label: 'Backlog' },
    { value: 'in_progress', label: 'In Progress' },
    { value: 'done', label: 'Done' },
    { value: 'cancelled', label: 'Cancelled' },
];

type StatusFormDialogProps = {
    open: boolean;
    /** Status being edited, or null in create mode. */
    status: StatusRow | null;
    onClose: () => void;
};

/** Create/edit dialog for a workspace status; `status` selects the mode. */
export function StatusFormDialog({ open, status, onClose }: StatusFormDialogProps) {
    const form = useForm({ name: '', category: 'backlog' });

    // Seed at open: edit mode reuses the row's values, create mode resets.
    useEffect(() => {
        if (status) {
            form.setData({ name: status.name, category: status.category });
        } else {
            form.reset();
        }
    }, [status]);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (status) {
            form.patch(route('statuses.update', status.id), {
                onSuccess: () => onClose(),
            });
            return;
        }
        form.post(route('statuses.store'), {
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
            title={status ? 'Edit status' : 'New status'}
            description={status ? 'Rename or change category.' : 'Add a status to this workspace.'}
            submitLabel={status ? 'Save' : 'Create'}
            processing={form.processing}
            onSubmit={submit}
        >
            <div className="grid gap-2">
                <Label htmlFor={status ? 'edit-name' : 'new-name'}>Name</Label>
                <Input
                    id={status ? 'edit-name' : 'new-name'}
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    required
                />
                {form.errors.name ? (
                    <p className="text-sm text-destructive">{form.errors.name}</p>
                ) : null}
            </div>
            <div className="grid gap-2">
                <Label>Category</Label>
                <Select
                    value={form.data.category}
                    onValueChange={(v) => {
                        if (v) form.setData('category', v);
                    }}
                >
                    <SelectTrigger>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {CATEGORIES.map((c) => (
                            <SelectItem key={c.value} value={c.value}>
                                {c.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>
        </FormDialog>
    );
}

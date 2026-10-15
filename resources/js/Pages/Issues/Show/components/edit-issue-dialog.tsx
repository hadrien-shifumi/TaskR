import { FormDialog } from '@/components/form-dialog';
import { PrioritySelect } from '@/components/priority-select';
import { Input } from '@/components/ui/input';
import { Label as LabelField } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/react';
import type { IssueDetail } from '../types';

type EditIssueDialogProps = {
    issue: IssueDetail;
    open: boolean;
    onClose: () => void;
};

/** Content edit dialog: title, description, priority and due date. */
export function EditIssueDialog({ issue, open, onClose }: EditIssueDialogProps) {
    const editForm = useForm({
        title: issue.title,
        description: issue.description ?? '',
        priority: issue.priority,
        due_date: issue.dueDate ? issue.dueDate.slice(0, 10) : '',
    });

    const submitEdit = (e: React.FormEvent) => {
        e.preventDefault();
        editForm.patch(route('issues.update', issue.id), {
            onSuccess: () => onClose(),
        });
    };

    return (
        <FormDialog
            open={open}
            onOpenChange={(o) => {
                if (!o) onClose();
            }}
            title="Edit issue"
            description="Update the issue content."
            submitLabel="Save"
            processing={editForm.processing}
            onSubmit={submitEdit}
        >
            <div className="grid gap-2">
                <LabelField htmlFor="edit-title">Title</LabelField>
                <Input
                    id="edit-title"
                    value={editForm.data.title}
                    onChange={(e) => editForm.setData('title', e.target.value)}
                    required
                />
                {editForm.errors.title ? (
                    <p className="text-sm text-destructive">{editForm.errors.title}</p>
                ) : null}
            </div>
            <div className="grid gap-2">
                <LabelField htmlFor="edit-description">Description</LabelField>
                <Textarea
                    id="edit-description"
                    value={editForm.data.description}
                    onChange={(e) => editForm.setData('description', e.target.value)}
                />
            </div>
            <div className="grid grid-cols-2 gap-4">
                <div className="grid gap-2">
                    <LabelField>Priority</LabelField>
                    <PrioritySelect
                        value={editForm.data.priority}
                        onValueChange={(priority) => editForm.setData('priority', priority)}
                    />
                </div>
                <div className="grid gap-2">
                    <LabelField htmlFor="edit-due">Due date</LabelField>
                    <Input
                        id="edit-due"
                        type="date"
                        value={editForm.data.due_date}
                        onChange={(e) => editForm.setData('due_date', e.target.value)}
                    />
                </div>
            </div>
        </FormDialog>
    );
}

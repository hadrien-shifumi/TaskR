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
import { useForm } from '@inertiajs/react';
import type { IssueDetail } from '../types';

type EditIssueDialogProps = {
    issue: IssueDetail;
    open: boolean;
    onClose: () => void;
};

/**
 * Title edition — description, status, priority, dates, people and labels
 * all live in the page itself now. The update action requires the title and
 * rewrites every field it knows, so the untouched ones travel along.
 */
export function EditIssueDialog({ issue, open, onClose }: EditIssueDialogProps) {
    return (
        <Dialog
            open={open}
            onOpenChange={(o) => {
                if (!o) onClose();
            }}
        >
            {open ? <EditTitleForm issue={issue} onClose={onClose} /> : null}
        </Dialog>
    );
}

function EditTitleForm({ issue, onClose }: { issue: IssueDetail; onClose: () => void }) {
    const titleForm = useForm({
        title: issue.title,
        description: issue.description ?? '',
        priority: issue.priority,
        due_date: issue.dueDate ? issue.dueDate.slice(0, 10) : '',
    });

    const submitEdit = (e: React.FormEvent) => {
        e.preventDefault();
        titleForm.patch(route('issues.update', issue.id), {
            onSuccess: () => onClose(),
        });
    };

    return (
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Edit title</DialogTitle>
                <DialogDescription>Rename this issue.</DialogDescription>
            </DialogHeader>
            <form onSubmit={submitEdit} className="grid gap-4">
                <div className="grid gap-2">
                    <Label htmlFor="edit-issue-title">Title</Label>
                    <Input
                        id="edit-issue-title"
                        value={titleForm.data.title}
                        onChange={(e) => titleForm.setData('title', e.target.value)}
                        required
                        aria-invalid={titleForm.errors.title ? true : undefined}
                    />
                    {titleForm.errors.title ? (
                        <p className="text-sm text-destructive">{titleForm.errors.title}</p>
                    ) : null}
                </div>
                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" data-test="title-save" disabled={titleForm.processing}>
                        Save
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { router } from '@inertiajs/react';
import type { IssueDetail } from '../types';

type DeleteIssueDialogProps = {
    /** Issue being deleted, or null when the dialog is closed. */
    issue: Pick<IssueDetail, 'id' | 'title'> | null;
    onClose: () => void;
};

/** Deletion is always allowed: children are detached and become root issues. */
export function DeleteIssueDialog({ issue, onClose }: DeleteIssueDialogProps) {
    const confirmDelete = () => {
        if (!issue) return;
        router.delete(route('issues.destroy', issue.id), {
            onSuccess: () => onClose(),
        });
    };

    return (
        <Dialog
            open={issue !== null}
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            {issue ? (
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete "{issue.title}"?</DialogTitle>
                        <DialogDescription>
                            The issue is removed and its children become root issues. This cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="ghost" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button variant="destructive" data-test="confirm-delete-issue" onClick={confirmDelete}>
                            Delete
                        </Button>
                    </DialogFooter>
                </DialogContent>
            ) : null}
        </Dialog>
    );
}

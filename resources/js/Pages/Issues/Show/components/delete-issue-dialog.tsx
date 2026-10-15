import { Banner } from '@/components/banner';
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
import { formatHours } from './format';

type DeleteIssueDialogProps = {
    /** Issue being deleted, or null when the dialog is closed. */
    issue: IssueDetail | null;
    commentsCount: number;
    onClose: () => void;
};

/**
 * Maquette dlg-delete-issue: a banner-danger lists the cascade inventory —
 * logged time and comments are lost, child issues become root issues.
 */
export function DeleteIssueDialog({ issue, commentsCount, onClose }: DeleteIssueDialogProps) {
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
                        <DialogTitle>Delete this issue?</DialogTitle>
                        <DialogDescription>“{issue.title}”</DialogDescription>
                    </DialogHeader>
                    <CascadeBanner issue={issue} commentsCount={commentsCount} />
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

function CascadeBanner({ issue, commentsCount }: { issue: IssueDetail; commentsCount: number }) {
    const lost: string[] = [];
    if (issue.loggedHours > 0) lost.push(`the logged time (${formatHours(issue.loggedHours)})`);
    if (commentsCount > 0) lost.push(`${commentsCount} ${commentsCount === 1 ? 'comment' : 'comments'}`);

    return (
        <Banner variant="danger">
            {lost.length > 0 ? (
                <>Deletion also removes {lost.join(' and ')}.</>
            ) : (
                <>This cannot be undone.</>
            )}
            {issue.children.length > 0
                ? ` ${issue.children.length} child ${
                      issue.children.length === 1 ? 'issue becomes a root issue' : 'issues become root issues'
                  }.`
                : ''}
        </Banner>
    );
}

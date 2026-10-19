import { IssueDetail } from '@/Pages/Issues/Show/issue-detail';
import type {
    CommentItem,
    IssueDetail as IssueDetailType,
    TimeEntry,
} from '@/Pages/Issues/Show/types';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { router } from '@inertiajs/react';
import type { LabelRef, UserRef } from '@/types';

export type IssueDetailPayload = {
    issue: IssueDetailType;
    comments: CommentItem[];
    timeEntries: TimeEntry[];
    statuses: { id: string; name: string; category: string }[];
    members: UserRef[];
    labels: LabelRef[];
    candidates: { id: string; title: string }[];
};

/**
 * Board URL with the current filters preserved. With `$issueId`, the board
 * renders the issue detail modal; without it, the modal is closed.
 */
export function boardIssueUrl(
    projectId: string,
    filters: Record<string, string>,
    parentsOnly: boolean,
    issueId?: string,
): string {
    const params: Record<string, string> = { ...filters };
    if (parentsOnly) {
        params.parents = '1';
    }
    if (issueId !== undefined) {
        params.issue = issueId;
    }

    const query = new URLSearchParams(params).toString();

    return query !== ''
        ? `${route('projects.board', projectId)}?${query}`
        : route('projects.board', projectId);
}

type IssueDetailDialogProps = {
    projectId: string;
    filters: Record<string, string>;
    parentsOnly: boolean;
    detail: IssueDetailPayload;
};

/**
 * Large modal rendering the issue fiche on top of the board. The open
 * state is URL-driven (`?issue=`): closing navigates back to the plain
 * board URL, and parent/child links swap the modal content by swapping
 * the `issue` param.
 */
export function IssueDetailDialog({ projectId, filters, parentsOnly, detail }: IssueDetailDialogProps) {
    const closeUrl = boardIssueUrl(projectId, filters, parentsOnly);

    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) {
                    router.get(closeUrl);
                }
            }}
        >
            <DialogContent
                data-test="issue-detail-dialog"
                className="max-h-[88vh] gap-0 overflow-y-auto p-0 sm:max-w-5xl"
            >
                <div className="p-6">
                    <IssueDetail
                        issue={detail.issue}
                        comments={detail.comments}
                        timeEntries={detail.timeEntries}
                        statuses={detail.statuses}
                        members={detail.members}
                        labels={detail.labels}
                        candidates={detail.candidates}
                        buildIssueHref={(issueId) => boardIssueUrl(projectId, filters, parentsOnly, issueId)}
                    />
                </div>
            </DialogContent>
        </Dialog>
    );
}

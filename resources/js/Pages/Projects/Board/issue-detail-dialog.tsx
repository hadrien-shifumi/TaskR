import { CopyIssueIdButton } from '@/Pages/Issues/Show/components/copy-issue-id-button';
import { IssueDetail } from '@/Pages/Issues/Show/issue-detail';
import type {
    ActivityEvent,
    CommentItem,
    IssueDetail as IssueDetailType,
    TimeEntry,
} from '@/Pages/Issues/Show/types';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent } from '@/components/ui/dialog';
import { XIcon } from 'lucide-react';
import { router } from '@inertiajs/react';
import type { LabelRef, MilestoneOption, UserRef } from '@/types';

export type IssueDetailPayload = {
    issue: IssueDetailType;
    comments: CommentItem[];
    timeEntries: TimeEntry[];
    activity: ActivityEvent[];
    statuses: { id: string; name: string; category: string }[];
    members: UserRef[];
    labels: LabelRef[];
    candidates: { id: string; title: string }[];
    dependencyCandidates: { id: string; title: string }[];
    milestoneOptions: MilestoneOption[];
    canContribute: boolean;
    /** Nameable projects of the workspace, for the knowledge pages' badges. */
    projects: { id: string; name: string }[];
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
                className="flex max-h-[88vh] flex-col gap-0 p-0 sm:max-w-5xl"
                showCloseButton={false}
            >
                <div
                    data-test="issue-modal-header"
                    className="flex shrink-0 items-center justify-between border-b px-4 py-3 sm:px-6"
                >
                    <div className="flex items-center gap-1.5">
                        <span
                            data-test="issue-ulid"
                            className="font-mono text-xs text-muted-foreground tabular-nums"
                        >
                            {detail.issue.id.slice(0, 7)}
                        </span>
                        <CopyIssueIdButton issueId={detail.issue.id} />
                    </div>
                    <DialogClose render={<Button variant="ghost" size="icon-sm" />}>
                        <XIcon />
                        <span className="sr-only">Close</span>
                    </DialogClose>
                </div>
                <div data-test="issue-detail-scroll" className="min-h-0 flex-1 overflow-y-auto px-4 py-5 sm:p-6">
                    <IssueDetail
                        issue={detail.issue}
                        comments={detail.comments}
                        timeEntries={detail.timeEntries}
                        activity={detail.activity}
                        statuses={detail.statuses}
                        members={detail.members}
                        labels={detail.labels}
                        candidates={detail.candidates}
                        dependencyCandidates={detail.dependencyCandidates}
                        milestoneOptions={detail.milestoneOptions}
                        canContribute={detail.canContribute}
                        projects={detail.projects}
                        sidebarSticky={false}
                        buildIssueHref={(issueId) => boardIssueUrl(projectId, filters, parentsOnly, issueId)}
                    />
                </div>
            </DialogContent>
        </Dialog>
    );
}

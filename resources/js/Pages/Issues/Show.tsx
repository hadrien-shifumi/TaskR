import { FlashBanner } from '@/components/flash-banner';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Archive, ArchiveRestore, MoreHorizontal, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { LabelRef, PageSharedProps, UserRef } from '@/types';
import { CopyIssueIdButton } from './Show/components/copy-issue-id-button';
import { DeleteIssueDialog } from './Show/components/delete-issue-dialog';
import { EditIssueDialog } from './Show/components/edit-issue-dialog';
import { IssueDetail } from './Show/issue-detail';
import type { CommentItem, IssueDetail as IssueDetailType, TimeEntry } from './Show/types';

type PageProps = {
    issue: IssueDetailType;
    comments: CommentItem[];
    timeEntries: TimeEntry[];
    statuses: { id: string; name: string; category: string }[];
    members: UserRef[];
    labels: LabelRef[];
    candidates: { id: string; title: string }[];
    isOwner: boolean;
};

export default function Show() {
    const { issue, comments, timeEntries, statuses, members, labels, candidates, isOwner } = usePage<PageProps>().props;
    const { workspace } = usePage<PageSharedProps>().props;
    const { t } = useTranslation();
    const owner = isOwner || workspace?.role === 'owner';
    const archiveAction = () => {
        router.post(route('issues.archive', issue.id));
    };
    const [editingTitle, setEditingTitle] = useState(false);
    const [deleting, setDeleting] = useState(false);

    return (
        <AppLayout
            leading={
                <>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label={t('issueShow.backToIssues')}
                        onClick={() => router.get(route('issues.index'))}
                    >
                        <ArrowLeft aria-hidden />
                    </Button>
                    <span
                        data-test="issue-ulid"
                        className="hidden font-mono text-xs text-muted-foreground tabular-nums sm:inline"
                    >
                        {issue.id.slice(0, 7)}
                    </span>
                </>
            }
            actions={
                <>
                    <CopyIssueIdButton issueId={issue.id} />
                    <DropdownMenu>
                        <DropdownMenuTrigger
                            aria-label={t('issueShow.issueActions')}
                            data-test="issue-menu"
                            className="grid size-7 place-items-center rounded-lg text-muted-foreground outline-none hover:bg-accent hover:text-foreground"
                        >
                            <MoreHorizontal className="size-4" aria-hidden />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuGroup>
                                <DropdownMenuItem onClick={() => setEditingTitle(true)}>
                                    <Pencil aria-hidden />
                                    {t('issueShow.editTitle')}
                                </DropdownMenuItem>
                                {owner && (issue.status.category === 'done' || issue.status.category === 'cancelled') ? (
                                    issue.archivedAt ? (
                                        <DropdownMenuItem onClick={archiveAction}>
                                            <ArchiveRestore aria-hidden />
                                            {t('issueShow.unarchiveIssue')}
                                        </DropdownMenuItem>
                                    ) : (
                                        <DropdownMenuItem onClick={archiveAction}>
                                            <Archive aria-hidden />
                                            {t('issueShow.archiveIssue')}
                                        </DropdownMenuItem>
                                    )
                                ) : null}
                                <DropdownMenuItem variant="destructive" onClick={() => setDeleting(true)}>
                                    <Trash2 aria-hidden />
                                    {t('issueShow.deleteIssue')}
                                </DropdownMenuItem>
                            </DropdownMenuGroup>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </>
            }
        >
            <Head title={issue.title} />

            <FlashBanner />

            <IssueDetail
                issue={issue}
                comments={comments}
                timeEntries={timeEntries}
                statuses={statuses}
                members={members}
                labels={labels}
                candidates={candidates}
            />

            <EditIssueDialog issue={issue} open={editingTitle} onClose={() => setEditingTitle(false)} />
            <DeleteIssueDialog
                issue={deleting ? issue : null}
                commentsCount={comments.length}
                onClose={() => setDeleting(false)}
            />
        </AppLayout>
    );
}

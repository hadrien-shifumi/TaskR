import { FlashBanner } from '@/components/flash-banner';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import { ArchiveRestore, Archive, ArrowLeft, MoreHorizontal, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { LabelRef, MilestoneOption, UserRef } from '@/types';
import { CopyIssueIdButton } from './Show/components/copy-issue-id-button';
import { DeleteIssueDialog } from './Show/components/delete-issue-dialog';
import { EditIssueDialog } from './Show/components/edit-issue-dialog';
import { IssueDetail } from './Show/issue-detail';
import type { ActivityEvent, CommentItem, IssueDetail as IssueDetailType, TimeEntry } from './Show/types';

type PageProps = {
    issue: IssueDetailType;
    /**
     * Deferred props: absent from the first paint, delivered by the
     * client's parallel partial reload. The shell renders with empty
     * threads until they land.
     */
    comments?: CommentItem[];
    timeEntries: TimeEntry[];
    activity?: ActivityEvent[];
    statuses: { id: string; name: string; category: string }[];
    members: UserRef[];
    labels: LabelRef[];
    candidates?: { id: string; title: string }[];
    dependencyCandidates?: { id: string; title: string }[];
    milestoneOptions: MilestoneOption[];
    canContribute: boolean;
    projects: { id: string; name: string }[];
    workspace?: { role?: string } | null;
};

export default function Show() {
    const { issue, comments, timeEntries, activity, statuses, members, labels, candidates, dependencyCandidates, milestoneOptions, canContribute, projects, workspace } = usePage<PageProps>().props;
    const { t } = useTranslation();
    const [editingTitle, setEditingTitle] = useState(false);
    const [deleting, setDeleting] = useState(false);

    // Deferred props render their sections empty until the parallel
    // partial reload lands; the dialogs read the same lists.
    const resolvedComments = comments ?? [];
    const resolvedActivity = activity ?? [];
    const resolvedCandidates = candidates ?? [];
    const resolvedDependencyCandidates = dependencyCandidates ?? [];

    // Archiving is an owner-only workspace management action, reserved to
    // closed issues (done or cancelled); unarchiving applies when archived.
    const canToggleArchive =
        workspace?.role === 'owner' &&
        (issue.isArchived || issue.status.category === 'done' || issue.status.category === 'cancelled');

    const toggleArchive = () => {
        router.post(route('issues.archive', issue.id));
    };

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
                                {canToggleArchive ? (
                                    <>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                            data-test="issue-archive-toggle"
                                            onClick={toggleArchive}
                                        >
                                            {issue.isArchived ? <ArchiveRestore aria-hidden /> : <Archive aria-hidden />}
                                            {issue.isArchived
                                                ? t('issueShow.unarchiveIssue')
                                                : t('issueShow.archiveIssue')}
                                        </DropdownMenuItem>
                                    </>
                                ) : null}
                                <DropdownMenuSeparator />
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
                comments={resolvedComments}
                timeEntries={timeEntries}
                activity={resolvedActivity}
                statuses={statuses}
                members={members}
                labels={labels}
                candidates={resolvedCandidates}
                dependencyCandidates={resolvedDependencyCandidates}
                milestoneOptions={milestoneOptions}
                canContribute={canContribute}
                projects={projects}
            />

            <EditIssueDialog issue={issue} open={editingTitle} onClose={() => setEditingTitle(false)} />
            <DeleteIssueDialog
                issue={deleting ? issue : null}
                commentsCount={resolvedComments.length}
                onClose={() => setDeleting(false)}
            />
        </AppLayout>
    );
}

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
import { ArchiveRestore, Archive, ArrowLeft, MoreHorizontal, Pencil, Play, Square, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { TimerProposalDialogs, useTimerControl, useTimerProposals } from '@/components/timer-widget';
import { StartTimerConflictDialog } from '@/components/start-timer-conflict-dialog';
import type { LabelRef, MilestoneOption, TimerState, UserRef } from '@/types';
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
    dependencyCandidates?: { id: string; title: string }[];
    milestoneOptions: MilestoneOption[];
    canContribute: boolean;
    projects: { id: string; name: string }[];
    /** Shared prop — the user's running timers in this workspace. */
    timers?: TimerState[];
    /** Shared by HandleInertiaRequests — drives the owner-only archive button. */
    workspace?: { role?: string } | null;
};

export default function Show() {
    const { issue, comments, timeEntries, activity, statuses, members, labels, dependencyCandidates, milestoneOptions, canContribute, projects, workspace, timers } = usePage<PageProps>().props;
    const { t } = useTranslation();
    const [editingTitle, setEditingTitle] = useState(false);
    const [deleting, setDeleting] = useState(false);
    /** Proposals of timers stopped from this fiche, confirmed one by one. */
    const timerProposals = useTimerProposals();
    const [timerError, setTimerError] = useState<string | null>(null);
    /** Open while the user must choose: stop the running timers or start in parallel. */
    const [startConflict, setStartConflict] = useState(false);
    const timerControl = useTimerControl({ onProposal: timerProposals.push, onError: setTimerError });
    const ownTimer = timers?.find((timer) => timer.issueId === issue.id) ?? null;
    const otherTimers = timers?.filter((timer) => timer.issueId !== issue.id) ?? [];

    // Stopping targets this issue's timer; starting while other timers run
    // asks the stop-or-parallel question first — nothing is replaced silently.
    const handleTimerToggle = () => {
        if (ownTimer) {
            timerControl.stop(ownTimer.id);
            return;
        }

        if (otherTimers.length > 0) {
            setStartConflict(true);
            return;
        }

        timerControl.start(issue.id);
    };

    // Deferred props render their sections empty until the parallel
    // partial reload lands; the dialogs read the same lists.
    const resolvedComments = comments ?? [];
    const resolvedActivity = activity ?? [];
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
                    <Button
                        variant={ownTimer ? 'secondary' : 'outline'}
                        size="sm"
                        data-test="issue-timer-toggle"
                        disabled={timerControl.processing}
                        onClick={handleTimerToggle}
                    >
                        {ownTimer ? <Square className="size-3.5" aria-hidden /> : <Play className="size-3.5" aria-hidden />}
                        {ownTimer ? t('timer.stop') : t('timer.start')}
                    </Button>
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

            {timerError ? (
                <Banner variant="danger" role="alert" className="mb-4 px-4 py-2.5">
                    {timerError}
                </Banner>
            ) : null}

            <IssueDetail
                issue={issue}
                comments={resolvedComments}
                timeEntries={timeEntries}
                activity={resolvedActivity}
                statuses={statuses}
                members={members}
                labels={labels}
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

            {startConflict ? (
                <StartTimerConflictDialog
                    runningTimers={otherTimers}
                    onStopOthers={() => {
                        setStartConflict(false);
                        timerControl.start(issue.id, { stopPrevious: true });
                    }}
                    onParallel={() => {
                        setStartConflict(false);
                        timerControl.start(issue.id);
                    }}
                    onClose={() => setStartConflict(false)}
                />
            ) : null}

            <TimerProposalDialogs proposals={timerProposals.proposals} onConsumed={timerProposals.consumeOne} />
        </AppLayout>
    );
}

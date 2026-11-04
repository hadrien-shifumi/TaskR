import { PriorityBadge, StatusBadge } from '@/components/issue-badges';
import { Badge } from '@/components/ui/badge';
import { Link } from '@inertiajs/react';
import { CornerDownRight } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import type { LabelRef, UserRef } from '@/types';
import { ActivitySection } from './components/activity-section';
import { CommentsSection } from './components/comments-section';
import { DescriptionSection } from './components/description-section';
import { formatDate } from './components/format';
import { IssueSidebar } from './components/issue-sidebar';
import { SectionHead } from './components/section-head';
import { TimeEntriesSection } from './components/time-entries-section';
import type { ActivityEvent, CommentItem, IssueDetail as IssueDetailType, TimeEntry } from './types';

type IssueDetailProps = {
    issue: IssueDetailType;
    comments: CommentItem[];
    timeEntries: TimeEntry[];
    activity: ActivityEvent[];
    statuses: { id: string; name: string; category: string }[];
    members: UserRef[];
    labels: LabelRef[];
    candidates: { id: string; title: string }[];
    dependencyCandidates: { id: string; title: string }[];
    /** Nameable projects of the workspace, for the knowledge pages' badges. */
    projects: { id: string; name: string }[];
    /**
     * Builds the href of parent/child links. Defaults to the full issue
     * page; the board's issue modal passes a board URL with an `issue`
     * param so hierarchy navigation stays inside the modal.
     */
    buildIssueHref?: (issueId: string) => string;
};

/**
 * Body of the issue fiche, shared between the full page (`Issues/Show`)
 * and the board's issue modal: meta line, title, badges, description,
 * child issues, time entries, comments, audit timeline and the sidebar.
 * Parent/child links route through `buildIssueHref` so the board modal
 * can swap its content in place.
 */
export function IssueDetail({
    issue,
    comments,
    timeEntries,
    activity,
    statuses,
    members,
    labels,
    candidates,
    dependencyCandidates,
    projects,
    buildIssueHref,
}: IssueDetailProps) {
    const { t } = useTranslation();

    const issueHref = (issueId: string) => buildIssueHref?.(issueId) ?? route('issues.show', issueId);

    return (
        <div className="grid items-start gap-7 lg:grid-cols-[1fr_280px]">
            <div className="min-w-0 space-y-7">
                <div>
                    <p className="mb-2.5 text-[13px] text-muted-foreground">
                        <Link
                            href={route('projects.health', issue.project.id)}
                            className="underline underline-offset-2 hover:text-foreground"
                        >
                            {issue.project.name}
                        </Link>
                        {issue.createdAt ? (
                            <>
                                {' '}
                                · {t('issueShow.opened', { date: formatDate(issue.createdAt) })}
                                {issue.creatorName ? <> {t('issueShow.by', { name: issue.creatorName })}</> : null}
                            </>
                        ) : null}
                    </p>
                    <h1 className="text-[26px] leading-[1.2] font-semibold tracking-[-0.02em]">
                        {issue.title}
                    </h1>
                    <div className="mt-1.5 flex flex-wrap items-center gap-2">
                        <StatusBadge name={issue.status.name} category={issue.status.category} />
                        <PriorityBadge priority={issue.priority} />
                        {issue.dueDate ? (
                            <Badge
                                variant="secondary"
                                className={`rounded-full ${
                                    issue.overdue
                                        ? 'bg-destructive/10 text-destructive'
                                        : 'bg-secondary text-muted-foreground'
                                }`}
                            >
                                {t('issueShow.due', { date: formatDate(issue.dueDate) })}
                                {issue.overdue ? t('issueShow.overdueSuffix') : ''}
                            </Badge>
                        ) : null}
                    </div>
                </div>

                <DescriptionSection issue={issue} />

                <section>
                    <SectionHead title={t('issueShow.childIssues')} count={issue.children.length} />
                    {issue.children.length > 0 ? (
                        <ul className="divide-y">
                            {issue.children.map((child) => (
                                <li key={child.id}>
                                    <Link
                                        href={issueHref(child.id)}
                                        className="flex items-center gap-2 py-2.25 text-sm hover:text-primary"
                                    >
                                        <CornerDownRight
                                            className="size-3.5 shrink-0 text-muted-foreground"
                                            aria-hidden
                                        />
                                        <span className="min-w-0 flex-1 truncate font-medium">
                                            {child.title}
                                        </span>
                                        {child.statusName ? (
                                            <Badge variant="outline" className="rounded-full text-muted-foreground">
                                                {child.statusName}
                                            </Badge>
                                        ) : null}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="text-sm text-muted-foreground">{t('issueShow.noChildIssues')}</p>
                    )}
                </section>

                <TimeEntriesSection issue={issue} timeEntries={timeEntries} />

                <CommentsSection issueId={issue.id} comments={comments} />

                <ActivitySection events={activity} />
            </div>

            <IssueSidebar
                issue={issue}
                statuses={statuses}
                members={members}
                labels={labels}
                candidates={candidates}
                dependencyCandidates={dependencyCandidates}
                projects={projects}
                buildIssueHref={buildIssueHref}
            />
        </div>
    );
}

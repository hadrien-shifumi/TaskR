import { FlashBanner } from '@/components/flash-banner';
import { Badge } from '@/components/ui/badge';
import { MarkdownView } from '@/components/ui/markdown-editor';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { LabelRef, UserRef } from '@/types';
import { CommentsSection } from './Show/components/comments-section';
import { EditIssueDialog } from './Show/components/edit-issue-dialog';
import { IssueSidebar } from './Show/components/issue-sidebar';
import { TimeEntriesSection } from './Show/components/time-entries-section';
import type { CommentItem, IssueDetail, TimeEntry } from './Show/types';

type PageProps = {
    issue: IssueDetail;
    comments: CommentItem[];
    timeEntries: TimeEntry[];
    statuses: { id: string; name: string; category: string }[];
    members: UserRef[];
    labels: LabelRef[];
    candidates: { id: string; title: string }[];
};

export default function Show() {
    const { issue, comments, timeEntries, statuses, members, labels, candidates } = usePage<PageProps>().props;
    const [editing, setEditing] = useState(false);

    return (
        <AppLayout title={issue.title}>
            <Head title={issue.title} />

            <FlashBanner />

            <div className="grid gap-8 lg:grid-cols-[1fr_280px]">
                <div className="min-w-0 space-y-6">
                    <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                        <p className="font-mono text-xs text-muted-foreground tabular-nums select-all">{issue.id}</p>
                    </div>

                    {issue.parent ? (
                        <p className="text-sm text-muted-foreground">
                            Parent:{' '}
                            <Link href={route('issues.show', issue.parent.id)} className="hover:underline">
                                {issue.parent.title}
                            </Link>
                        </p>
                    ) : null}

                    <div>
                        <h2 className="mb-2 text-lg font-semibold">Description</h2>
                        {issue.description ? (
                            <MarkdownView value={issue.description} className="text-sm text-muted-foreground" />
                        ) : (
                            <p className="text-sm italic text-muted-foreground">No description.</p>
                        )}
                    </div>

                    <div>
                        <h2 className="mb-2 text-lg font-semibold">
                            Children ({issue.children.length})
                        </h2>
                        <ul className="divide-y rounded-md border">
                            {issue.children.map((child) => (
                                <li key={child.id} className="flex items-center gap-3 px-4 py-2 text-sm">
                                    <Link href={route('issues.show', child.id)} className="font-medium hover:underline">
                                        {child.title}
                                    </Link>
                                    {child.statusName ? <Badge variant="outline">{child.statusName}</Badge> : null}
                                    <span className="ml-auto text-muted-foreground">
                                        {child.assignees.map((a) => a.name).join(', ')}
                                    </span>
                                </li>
                            ))}
                            {issue.children.length === 0 ? (
                                <li className="px-4 py-3 text-sm text-muted-foreground">No children.</li>
                            ) : null}
                        </ul>
                    </div>

                    <CommentsSection issueId={issue.id} comments={comments} />

                    <TimeEntriesSection issue={issue} timeEntries={timeEntries} />
                </div>

                <IssueSidebar
                    issue={issue}
                    statuses={statuses}
                    members={members}
                    labels={labels}
                    candidates={candidates}
                    onEditIssue={() => setEditing(true)}
                />
            </div>

            <EditIssueDialog issue={issue} open={editing} onClose={() => setEditing(false)} />
        </AppLayout>
    );
}

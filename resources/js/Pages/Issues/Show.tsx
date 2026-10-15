import { FlashBanner } from '@/components/flash-banner';
import { PriorityBadge, StatusBadge } from '@/components/issue-badges';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Check, Copy, CornerDownRight, MoreHorizontal, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { LabelRef, UserRef } from '@/types';
import { CommentsSection } from './Show/components/comments-section';
import { DeleteIssueDialog } from './Show/components/delete-issue-dialog';
import { DescriptionSection } from './Show/components/description-section';
import { EditIssueDialog } from './Show/components/edit-issue-dialog';
import { formatDate } from './Show/components/format';
import { IssueSidebar } from './Show/components/issue-sidebar';
import { SectionHead } from './Show/components/section-head';
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
    const [editingTitle, setEditingTitle] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [copied, setCopied] = useState(false);

    const copyId = () => {
        navigator.clipboard?.writeText(issue.id).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 1500);
        });
    };

    return (
        <AppLayout
            leading={
                <>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Back to issues"
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
                    <Button variant="ghost" size="sm" data-test="copy-identifier" onClick={copyId}>
                        {copied ? <Check className="size-3.5" aria-hidden /> : <Copy className="size-3.5" aria-hidden />}
                        {copied ? 'Copied' : 'Copy identifier'}
                    </Button>
                    <DropdownMenu>
                        <DropdownMenuTrigger
                            aria-label="Issue actions"
                            data-test="issue-menu"
                            className="grid size-7 place-items-center rounded-lg text-muted-foreground outline-none hover:bg-accent hover:text-foreground"
                        >
                            <MoreHorizontal className="size-4" aria-hidden />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuGroup>
                                <DropdownMenuItem onClick={() => setEditingTitle(true)}>
                                    <Pencil aria-hidden />
                                    Edit title…
                                </DropdownMenuItem>
                                <DropdownMenuItem variant="destructive" onClick={() => setDeleting(true)}>
                                    <Trash2 aria-hidden />
                                    Delete issue…
                                </DropdownMenuItem>
                            </DropdownMenuGroup>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </>
            }
        >
            <Head title={issue.title} />

            <FlashBanner />

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
                            {issue.createdAt ? <> · opened {formatDate(issue.createdAt)}</> : null}
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
                                    due {formatDate(issue.dueDate)}
                                    {issue.overdue ? ' · overdue' : ''}
                                </Badge>
                            ) : null}
                        </div>
                    </div>

                    <DescriptionSection issue={issue} />

                    <section>
                        <SectionHead title="Child issues" count={issue.children.length} />
                        {issue.children.length > 0 ? (
                            <ul className="divide-y">
                                {issue.children.map((child) => (
                                    <li key={child.id}>
                                        <Link
                                            href={route('issues.show', child.id)}
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
                            <p className="text-sm text-muted-foreground">No child issues.</p>
                        )}
                    </section>

                    <TimeEntriesSection issue={issue} timeEntries={timeEntries} />

                    <CommentsSection issueId={issue.id} comments={comments} />
                </div>

                <IssueSidebar
                    issue={issue}
                    statuses={statuses}
                    members={members}
                    labels={labels}
                    candidates={candidates}
                />
            </div>

            <EditIssueDialog issue={issue} open={editingTitle} onClose={() => setEditingTitle(false)} />
            <DeleteIssueDialog
                issue={deleting ? issue : null}
                commentsCount={comments.length}
                onClose={() => setDeleting(false)}
            />
        </AppLayout>
    );
}

<?php

namespace App\Actions\Issues;

use App\Models\Issue;
use App\Models\User;
use App\Presenters\Issues\IssuePresenter;
use App\Support\WorkspaceContext;
use Lorisleiva\Actions\Concerns\AsObject;

class GetIssue
{
    use AsObject;

    /**
     * Full body of a single issue — the specification an agent reads
     * after picking a candidate from the list. Comments and time
     * entries stay out: list_comments and list_time_entries cover
     * them, loggedHours already aggregates the time spent.
     *
     * @return array<string, mixed>
     */
    public function handle(Issue $issue, ?User $user = null): array
    {
        // Out-of-scope issues fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessIssueAccessible($user, $issue);
        }

        $issue->load(['project', 'status', 'labels', 'assignees', 'timeEntries', 'creator']);

        $parent = $issue->parent()
            ->with('status')
            ->first();

        $children = $issue->children()
            ->with(['status', 'assignees'])
            ->orderBy('created_at')
            ->get();

        return IssuePresenter::detail($issue, $parent, $children);
    }
}

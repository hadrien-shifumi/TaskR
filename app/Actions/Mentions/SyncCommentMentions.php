<?php

namespace App\Actions\Mentions;

use App\Models\Comment;
use App\Models\Project;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Workspace;
use App\Support\MentionParser;
use Lorisleiva\Actions\Concerns\AsObject;

class SyncCommentMentions
{
    use AsObject;

    /**
     * Recompute the mention rows of a comment from its current body: the
     * previous rows are dropped, then the resolved targets are recreated
     * — editing a comment replaces its mentions instead of stacking them.
     *
     * The workspace is derived from the comment's issue (not from the
     * request context), so the rows always land on the right tenant.
     */
    public function handle(Comment $comment): void
    {
        $comment->mentions()->delete();

        $issue = $comment->issue()->withoutGlobalScope(WorkspaceScope::class)->firstOrFail();
        $workspaceId = Project::withoutWorkspaceScope()
            ->whereKey($issue->project_id)
            ->value('workspace_id');
        $workspace = Workspace::query()->findOrFail((string) $workspaceId);
        $author = $comment->author()->firstOrFail();

        $targets = MentionParser::resolve($workspace, $author, $comment->body, $issue->project_id);

        foreach ($targets as $target) {
            $comment->mentions()->create([
                'workspace_id' => $workspace->getKey(),
                'user_id' => $target['user']->getKey(),
                'type' => $target['type'],
                'project_id' => $target['projectId'],
            ]);
        }
    }
}

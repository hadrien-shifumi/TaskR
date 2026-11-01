<?php

namespace Tests\Feature\Audit;

use App\Actions\Comments\AddComment;
use App\Actions\Comments\DeleteComment;
use App\Actions\Comments\UpdateComment;
use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommentActivityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: User, 2: Issue}
     */
    private function setupIssue(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);
        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Page de profil'], $owner);

        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        return [$owner, $member, $issue];
    }

    public function test_add_comment_emits_comment_created_on_the_parent_issue(): void
    {
        [$owner, , $issue] = $this->setupIssue();

        AddComment::run($issue, $owner, 'Premier retour');

        $event = AuditEvent::query()->where('type', AuditEventType::CommentCreated->value)->firstOrFail();

        // A comment is not an audit subject: the journal targets the parent
        // issue and carries the comment details in changes.
        $this->assertSame($issue->id, $event->issue_id);
        $this->assertSame($issue->project_id, $event->project_id);
        $this->assertSame($owner->id, $event->actor_id);
        $this->assertNull($event->knowledge_page_id);
        $this->assertSame('Premier retour', $event->changes['body']['after']);
        $this->assertNull($event->changes['body']['before']);
        $this->assertNotNull($event->changes['comment_id']);
    }

    public function test_update_comment_emits_comment_updated_with_the_body_diff(): void
    {
        [$owner, $member, $issue] = $this->setupIssue();
        $comment = AddComment::run($issue, $member, 'Version initiale');

        UpdateComment::run($comment, 'Version corrigee', $member);

        $event = AuditEvent::query()->where('type', AuditEventType::CommentUpdated->value)->firstOrFail();

        $this->assertSame($issue->id, $event->issue_id);
        $this->assertSame($member->id, $event->actor_id);
        $this->assertSame($comment->id, $event->changes['comment_id']);
        $this->assertSame(['before' => 'Version initiale', 'after' => 'Version corrigee'], $event->changes['body']);
    }

    public function test_delete_comment_emits_comment_deleted_with_the_body_snapshot(): void
    {
        [$owner, $member, $issue] = $this->setupIssue();
        $comment = AddComment::run($issue, $member, 'A supprimer');

        DeleteComment::run($comment, $member);

        $event = AuditEvent::query()->where('type', AuditEventType::CommentDeleted->value)->firstOrFail();

        $this->assertSame($issue->id, $event->issue_id);
        $this->assertSame($member->id, $event->actor_id);
        $this->assertSame($comment->id, $event->changes['comment_id']);
        $this->assertSame('A supprimer', $event->changes['body']['before']);
        $this->assertNull($event->changes['body']['after']);
    }

    public function test_comment_events_are_isolated_per_workspace(): void
    {
        [$owner, , $issue] = $this->setupIssue();
        AddComment::run($issue, $owner, 'Isole');

        $otherOwner = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($otherOwner, ['name' => 'Autre WS']);
        app(WorkspaceContext::class)->set($otherWorkspace);

        $this->assertSame(0, AuditEvent::query()->where('type', AuditEventType::CommentCreated->value)->count());
    }
}

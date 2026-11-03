<?php

namespace Tests\Feature\Audit;

use App\Actions\Comments\AddComment;
use App\Actions\Comments\DeleteComment;
use App\Actions\Comments\UpdateComment;
use App\Actions\Issues\CreateIssue;
use App\Actions\Issues\ShowIssuePage;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommentActivityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: User, 2: Issue, 3: Workspace}
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

        return [$owner, $member, $issue, $workspace];
    }

    public function test_add_comment_journals_no_body(): void
    {
        [$owner, , $issue] = $this->setupIssue();

        AddComment::run($issue, $owner, 'Premier retour');

        $event = AuditEvent::query()->where('type', AuditEventType::CommentCreated->value)->firstOrFail();

        // A comment is not an audit subject: the journal targets the parent
        // issue. The body itself is already displayed on the page, so the
        // journal only records that a comment was added, never its content.
        $this->assertSame($issue->id, $event->issue_id);
        $this->assertSame($issue->project_id, $event->project_id);
        $this->assertSame($owner->id, $event->actor_id);
        $this->assertNull($event->knowledge_page_id);
        $this->assertArrayNotHasKey('body', $event->changes);
        $this->assertArrayNotHasKey('comment_id', $event->changes);
    }

    public function test_add_comment_exposes_the_comment_id_outside_changes(): void
    {
        [$owner, , $issue] = $this->setupIssue();

        $comment = AddComment::run($issue, $owner, 'Premier retour');

        $event = AuditEvent::query()->where('type', AuditEventType::CommentCreated->value)->firstOrFail();

        $this->assertSame($comment->id, $event->comment_id);
        $this->assertSame([], $event->changes);
    }

    public function test_update_comment_journals_no_body(): void
    {
        [$owner, $member, $issue] = $this->setupIssue();
        $comment = AddComment::run($issue, $member, 'Version initiale');

        UpdateComment::run($comment, 'Version corrigee', $member);

        $event = AuditEvent::query()->where('type', AuditEventType::CommentUpdated->value)->firstOrFail();

        $this->assertSame($issue->id, $event->issue_id);
        $this->assertSame($member->id, $event->actor_id);
        $this->assertSame($comment->id, $event->comment_id);
        $this->assertArrayNotHasKey('body', $event->changes);
        $this->assertSame([], $event->changes);
    }

    public function test_update_comment_with_the_same_body_journals_nothing(): void
    {
        [$owner, $member, $issue] = $this->setupIssue();
        $comment = AddComment::run($issue, $member, 'Inchange');

        UpdateComment::run($comment, 'Inchange', $member);

        $this->assertSame(
            0,
            AuditEvent::query()->where('type', AuditEventType::CommentUpdated->value)->count(),
        );
    }

    public function test_delete_comment_keeps_a_body_snapshot(): void
    {
        [$owner, $member, $issue] = $this->setupIssue();
        $comment = AddComment::run($issue, $member, 'A supprimer');

        DeleteComment::run($comment, $member);

        $event = AuditEvent::query()->where('type', AuditEventType::CommentDeleted->value)->firstOrFail();

        // The comment is gone: the journal keeps its last body — the only
        // remaining trace.
        $this->assertSame($issue->id, $event->issue_id);
        $this->assertSame($member->id, $event->actor_id);
        $this->assertSame($comment->id, $event->comment_id);
        $this->assertSame('A supprimer', $event->changes['body']['before']);
        $this->assertNull($event->changes['body']['after']);
    }

    public function test_delete_comment_snapshot_is_truncated_to_2000_characters(): void
    {
        [$owner, $member, $issue] = $this->setupIssue();
        $comment = AddComment::run($issue, $member, str_repeat('a', 5000));

        DeleteComment::run($comment, $member);

        $event = AuditEvent::query()->where('type', AuditEventType::CommentDeleted->value)->firstOrFail();

        $this->assertSame(2000, mb_strlen($event->changes['body']['before']));
    }

    public function test_timeline_payload_exposes_comment_id(): void
    {
        [$owner, , $issue, $workspace] = $this->setupIssue();
        $comment = AddComment::run($issue, $owner, 'Premier retour');

        $payload = ShowIssuePage::run($workspace, $issue, $owner);

        $event = collect($payload['activity'])->firstWhere('type', 'comment.created');

        $this->assertNotNull($event);
        $this->assertSame($comment->id, $event['commentId']);
        $this->assertSame([], $event['changes']);
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

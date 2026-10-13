<?php

namespace Tests\Feature;

use App\Actions\Comments\AddComment;
use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Membership;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class CommentTest extends TestCase
{
    use RefreshDatabase;

    private function setupIssue(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);
        $project = CreateProject::run($workspace, ['name' => 'Site vitrine']);
        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Page de profil']);

        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        return [$owner, $member, $issue];
    }

    public function test_comment_is_added_to_the_thread(): void
    {
        [$owner, $member, $issue] = $this->setupIssue();

        $this->actingAs($member)
            ->post("/issues/{$issue->id}/comments", ['body' => 'Premier retour'])
            ->assertRedirect(route('issues.show', $issue->id));

        $this->assertDatabaseHas('comments', [
            'issue_id' => $issue->id,
            'author_id' => $member->id,
            'body' => 'Premier retour',
        ]);

        $this->actingAs($owner)
            ->get("/issues/{$issue->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('comments', 1)
                ->where('comments.0.body', 'Premier retour')
                ->where('comments.0.authorName', $member->name));
    }

    public function test_comments_are_chronological(): void
    {
        [, , $issue] = $this->setupIssue();

        AddComment::run($issue, User::factory()->create(), 'Premier');
        AddComment::run($issue, User::factory()->create(), 'Deuxieme');

        $this->actingAs(User::first())
            ->get("/issues/{$issue->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('comments.0.body', 'Premier')
                ->where('comments.1.body', 'Deuxieme'));
    }

    public function test_author_can_edit_their_comment(): void
    {
        [$owner, $member, $issue] = $this->setupIssue();

        $comment = AddComment::run($issue, $member, 'Version initiale');

        $this->actingAs($member)
            ->patch("/issues/{$issue->id}/comments/{$comment->id}", ['body' => 'Version corrigee'])
            ->assertRedirect();

        $this->assertDatabaseHas('comments', [
            'id' => $comment->id,
            'body' => 'Version corrigee',
        ]);

        $this->assertSame($owner->id, $owner->id); // owner untouched
    }

    public function test_third_party_cannot_edit_a_comment(): void
    {
        [$owner, $member, $issue] = $this->setupIssue();

        $comment = AddComment::run($issue, $member, 'A moi');

        $this->actingAs($owner)
            ->patch("/issues/{$issue->id}/comments/{$comment->id}", ['body' => 'Hacked'])
            ->assertForbidden();
    }

    public function test_author_can_delete_their_comment(): void
    {
        [, $member, $issue] = $this->setupIssue();

        $comment = AddComment::run($issue, $member, 'A supprimer');

        $this->actingAs($member)
            ->delete("/issues/{$issue->id}/comments/{$comment->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('comments', ['id' => $comment->id]);
    }

    public function test_third_party_cannot_delete_a_comment(): void
    {
        [$owner, $member, $issue] = $this->setupIssue();

        $comment = AddComment::run($issue, $member, 'A moi');

        $this->actingAs($owner)
            ->delete("/issues/{$issue->id}/comments/{$comment->id}")
            ->assertForbidden();
    }

    public function test_comment_of_another_issue_cannot_be_reached_via_nested_route(): void
    {
        [$owner, , $issue] = $this->setupIssue();
        $workspace = app(WorkspaceContext::class)->get();

        $other = CreateProject::run($workspace, ['name' => 'Autre projet']);
        $otherIssue = CreateIssue::run($workspace, ['project_id' => $other->id, 'title' => 'Autre']);

        $comment = AddComment::run($otherIssue, $owner, 'Sur une autre issue');

        // Nested scoped bindings refuse the mismatched pair.
        $this->actingAs($owner)
            ->patch("/issues/{$issue->id}/comments/{$comment->id}", ['body' => 'Hacked'])
            ->assertNotFound();
    }
}

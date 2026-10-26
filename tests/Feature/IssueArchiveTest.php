<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\StatusCategory;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class IssueArchiveTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Shared skeleton for the archive epic: a workspace with its default
     * statuses (one per category) and an empty project.
     *
     * @return array{0: User, 1: Workspace, 2: Project}
     */
    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);

        app(WorkspaceContext::class)->set($workspace);

        $project = $workspace->projects()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Site vitrine',
        ]);

        return [$owner, $workspace, $project];
    }

    private function statusOf(Workspace $workspace, StatusCategory $category): Status
    {
        return $workspace->statuses()
            ->where('category', $category)
            ->firstOrFail();
    }

    private function createIssue(Workspace $workspace, Project $project, StatusCategory $category, array $attributes = []): Issue
    {
        return Issue::create(array_merge([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => ucfirst(fake()->words(3, true)),
            'status_id' => $this->statusOf($workspace, $category)->id,
        ], $attributes));
    }

    public function test_unarchived_scope_excludes_archived_issues(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $active = $this->createIssue($workspace, $project, StatusCategory::Backlog);
        $archived = $this->createIssue($workspace, $project, StatusCategory::Done);
        $archived->update(['archived_at' => now()]);

        $ids = Issue::query()->unarchived()->pluck('id');

        $this->assertTrue($ids->contains($active->id));
        $this->assertFalse($ids->contains($archived->id));
    }

    public function test_archived_scope_returns_only_archived_issues(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $active = $this->createIssue($workspace, $project, StatusCategory::Backlog);
        $archived = $this->createIssue($workspace, $project, StatusCategory::Done);
        $archived->update(['archived_at' => now()]);

        $ids = Issue::query()->archived()->pluck('id');

        $this->assertTrue($ids->contains($archived->id));
        $this->assertFalse($ids->contains($active->id));
    }

    public function test_is_archived_reflects_archived_at_state(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $issue = $this->createIssue($workspace, $project, StatusCategory::Done);

        $this->assertFalse($issue->isArchived());

        $issue->update(['archived_at' => now()]);
        $this->assertTrue($issue->fresh()->isArchived());

        $issue->update(['archived_at' => null]);
        $this->assertFalse($issue->fresh()->isArchived());
    }

    public function test_archived_at_defaults_to_null(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $issue = $this->createIssue($workspace, $project, StatusCategory::InProgress);

        $this->assertNull($issue->fresh()->archived_at);
        $this->assertDatabaseHas('issues', [
            'id' => $issue->id,
            'archived_at' => null,
        ]);
    }

    private function memberOf(Workspace $workspace): User
    {
        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        return $member;
    }

    public function test_owner_can_archive_a_done_issue(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $issue = $this->createIssue($workspace, $project, StatusCategory::Done);

        $this->actingAs($owner)
            ->post("/issues/{$issue->id}/archive")
            ->assertRedirect()
            ->assertSessionHas('status', __('flash.issue.archived'));

        $this->assertNotNull($issue->fresh()->archived_at);
    }

    public function test_owner_can_archive_a_cancelled_issue(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $issue = $this->createIssue($workspace, $project, StatusCategory::Cancelled);

        $this->actingAs($owner)
            ->post("/issues/{$issue->id}/archive")
            ->assertRedirect();

        $this->assertNotNull($issue->fresh()->archived_at);
    }

    public function test_member_cannot_archive_an_issue(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $member = $this->memberOf($workspace);
        $issue = $this->createIssue($workspace, $project, StatusCategory::Done);

        $this->actingAs($member)
            ->post("/issues/{$issue->id}/archive")
            ->assertForbidden();

        $this->assertNull($issue->fresh()->archived_at);
    }

    public function test_archiving_an_open_issue_is_refused(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $backlog = $this->createIssue($workspace, $project, StatusCategory::Backlog);
        $inProgress = $this->createIssue($workspace, $project, StatusCategory::InProgress);

        $this->actingAs($owner)
            ->post("/issues/{$backlog->id}/archive")
            ->assertStatus(409);

        $this->actingAs($owner)
            ->post("/issues/{$inProgress->id}/archive")
            ->assertStatus(409);

        $this->assertNull($backlog->fresh()->archived_at);
        $this->assertNull($inProgress->fresh()->archived_at);
    }

    public function test_archiving_a_parent_cascades_to_descendants(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $parent = $this->createIssue($workspace, $project, StatusCategory::Done);
        $child = $this->createIssue($workspace, $project, StatusCategory::Done, ['parent_id' => $parent->id]);
        $grandChild = $this->createIssue($workspace, $project, StatusCategory::Cancelled, ['parent_id' => $child->id]);

        $this->actingAs($owner)
            ->post("/issues/{$parent->id}/archive")
            ->assertRedirect();

        $this->assertNotNull($parent->fresh()->archived_at);
        $this->assertNotNull($child->fresh()->archived_at);
        $this->assertNotNull($grandChild->fresh()->archived_at);
    }

    public function test_archiving_a_parent_with_an_open_child_is_refused(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $parent = $this->createIssue($workspace, $project, StatusCategory::Done);
        $openChild = $this->createIssue($workspace, $project, StatusCategory::InProgress, ['parent_id' => $parent->id]);

        $this->actingAs($owner)
            ->post("/issues/{$parent->id}/archive")
            ->assertStatus(409);

        $this->assertNull($parent->fresh()->archived_at);
        $this->assertNull($openChild->fresh()->archived_at);
    }

    public function test_owner_can_restore_an_archived_issue(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $issue = $this->createIssue($workspace, $project, StatusCategory::Done);
        $issue->update(['archived_at' => now()]);

        $this->actingAs($owner)
            ->post("/issues/{$issue->id}/archive")
            ->assertRedirect()
            ->assertSessionHas('status', __('flash.issue.unarchived'));

        $this->assertNull($issue->fresh()->archived_at);
    }

    public function test_restoring_a_parent_restores_its_descendants(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $parent = $this->createIssue($workspace, $project, StatusCategory::Done);
        $child = $this->createIssue($workspace, $project, StatusCategory::Done, ['parent_id' => $parent->id]);
        $parent->update(['archived_at' => now()]);
        $child->update(['archived_at' => now()]);

        $this->actingAs($owner)
            ->post("/issues/{$parent->id}/archive")
            ->assertRedirect();

        $this->assertNull($parent->fresh()->archived_at);
        $this->assertNull($child->fresh()->archived_at);
    }

    public function test_archiving_a_child_with_an_active_parent_is_refused(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $parent = $this->createIssue($workspace, $project, StatusCategory::Done);
        $child = $this->createIssue($workspace, $project, StatusCategory::Done, ['parent_id' => $parent->id]);

        // A child may never be more archived than its parent: archiving
        // the child while the parent stays active would break the invariant.
        $this->actingAs($owner)
            ->post("/issues/{$child->id}/archive")
            ->assertStatus(409);

        $this->assertNull($child->fresh()->archived_at);
    }

    public function test_member_of_another_workspace_cannot_archive_an_issue(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $issue = $this->createIssue($workspace, $project, StatusCategory::Done);

        $intruder = User::factory()->create();
        CreateWorkspace::run($intruder, ['name' => 'Theirs']);

        $this->actingAs($intruder)
            ->post("/issues/{$issue->id}/archive")
            ->assertNotFound();

        $this->assertNull($issue->fresh()->archived_at);
    }

    public function test_issue_detail_exposes_archive_state(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $issue = $this->createIssue($workspace, $project, StatusCategory::Done);
        $issue->update(['archived_at' => now()->subDay()]);
        $archivedAt = $issue->fresh()->archived_at->toISOString();

        $this->actingAs($owner)
            ->get("/issues/{$issue->id}")
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->where('issue.isArchived', true)
                    ->where('issue.archivedAt', $archivedAt)
            );
    }
}

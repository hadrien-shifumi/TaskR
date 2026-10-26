<?php

namespace Tests\Feature;

use App\Actions\Issues\ListIssues;
use App\Actions\Projects\GetProjectHealth;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\StatusCategory;
use App\Mcp\Servers\TaskrServer;
use App\Mcp\Tools\ListIssues as ListIssuesTool;
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

    public function test_owner_can_mass_archive_completed_issues_by_project(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $firstDone = $this->createIssue($workspace, $project, StatusCategory::Done);
        $secondDone = $this->createIssue($workspace, $project, StatusCategory::Done);
        $cancelled = $this->createIssue($workspace, $project, StatusCategory::Cancelled);
        $backlog = $this->createIssue($workspace, $project, StatusCategory::Backlog);

        $this->actingAs($owner)
            ->post("/projects/{$project->id}/issues/archive-completed")
            ->assertRedirect()
            ->assertSessionHas('status', __('flash.project.issuesArchived', ['count' => 3]));

        $this->assertNotNull($firstDone->fresh()->archived_at);
        $this->assertNotNull($secondDone->fresh()->archived_at);
        $this->assertNotNull($cancelled->fresh()->archived_at);
        $this->assertNull($backlog->fresh()->archived_at);
    }

    public function test_mass_archive_cascades_through_the_unitary_toggle(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $parent = $this->createIssue($workspace, $project, StatusCategory::Done);
        $child = $this->createIssue($workspace, $project, StatusCategory::Done, ['parent_id' => $parent->id]);

        $this->actingAs($owner)
            ->post("/projects/{$project->id}/issues/archive-completed")
            ->assertRedirect()
            ->assertSessionHas('status', __('flash.project.issuesArchived', ['count' => 1]));

        $this->assertNotNull($parent->fresh()->archived_at);
        $this->assertNotNull($child->fresh()->archived_at);
    }

    public function test_mass_archive_skips_subtrees_with_open_children(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $parent = $this->createIssue($workspace, $project, StatusCategory::Done);
        $this->createIssue($workspace, $project, StatusCategory::InProgress, ['parent_id' => $parent->id]);
        $standalone = $this->createIssue($workspace, $project, StatusCategory::Done);

        $this->actingAs($owner)
            ->post("/projects/{$project->id}/issues/archive-completed")
            ->assertRedirect()
            ->assertSessionHas('status', __('flash.project.issuesArchived', ['count' => 1]));

        $this->assertNull($parent->fresh()->archived_at);
        $this->assertNotNull($standalone->fresh()->archived_at);
    }

    public function test_member_cannot_mass_archive_completed_issues(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $member = $this->memberOf($workspace);
        $issue = $this->createIssue($workspace, $project, StatusCategory::Done);

        $this->actingAs($member)
            ->post("/projects/{$project->id}/issues/archive-completed")
            ->assertForbidden();

        $this->assertNull($issue->fresh()->archived_at);
    }

    public function test_mass_archive_only_touches_the_target_project(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $otherProject = $workspace->projects()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Autre projet',
        ]);
        $here = $this->createIssue($workspace, $project, StatusCategory::Done);
        $there = $this->createIssue($workspace, $otherProject, StatusCategory::Done);

        $this->actingAs($owner)
            ->post("/projects/{$project->id}/issues/archive-completed")
            ->assertRedirect();

        $this->assertNotNull($here->fresh()->archived_at);
        $this->assertNull($there->fresh()->archived_at);
    }

    public function test_archived_issues_are_excluded_from_the_issues_list(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $active = $this->createIssue($workspace, $project, StatusCategory::Backlog);
        $archived = $this->createIssue($workspace, $project, StatusCategory::Done);
        $archived->update(['archived_at' => now()]);

        $response = $this->actingAs($owner)->get('/issues');

        $response->assertInertia(
            fn (AssertableInertia $page) => $page
                ->has('issues', 1)
                ->where('issues.0.title', $active->title)
        );
    }

    public function test_list_issues_archived_param_composes_with_filters(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $active = $this->createIssue($workspace, $project, StatusCategory::Backlog);
        $archived = $this->createIssue($workspace, $project, StatusCategory::Done);
        $archived->update(['archived_at' => now()]);

        $default = ListIssues::run($workspace);
        $this->assertTrue(collect($default['issues'])->pluck('title')->contains($active->title));
        $this->assertFalse(collect($default['issues'])->pluck('title')->contains($archived->title));
        $this->assertSame(1, $default['total']);
        $this->assertSame(1, $default['totalUnfiltered']);

        $included = ListIssues::run($workspace, ['archived' => 'include']);
        $this->assertCount(2, $included['issues']);
        $this->assertSame(2, $included['total']);
        $this->assertSame(2, $included['totalUnfiltered']);

        $only = ListIssues::run($workspace, ['archived' => 'only']);
        $this->assertSame([$archived->title], collect($only['issues'])->pluck('title')->all());
        $this->assertSame(1, $only['total']);
    }

    public function test_archived_issues_leave_the_project_board(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $active = $this->createIssue($workspace, $project, StatusCategory::Backlog);
        $done = $this->createIssue($workspace, $project, StatusCategory::Done);
        $done->update(['archived_at' => now()]);

        $this->actingAs($owner)
            ->get("/projects/{$project->id}/board")
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('columns', 4)
                    ->has('columns.0.issues', 1)
                    ->has('columns.2.issues', 0)
            );
    }

    public function test_archived_issues_leave_project_health(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $active = $this->createIssue($workspace, $project, StatusCategory::Backlog, [
            'estimated_hours' => 2,
        ]);
        $archived = $this->createIssue($workspace, $project, StatusCategory::Done, [
            'estimated_hours' => 5,
        ]);
        $archived->update(['archived_at' => now()]);

        $health = GetProjectHealth::run($project);

        $this->assertSame(1, $health->totalIssues);
        $this->assertSame(1, $health->issuesByCategory['backlog']);
        $this->assertSame(0, $health->issuesByCategory['done']);
        $this->assertEquals(2, $health->estimatedHours);
    }

    public function test_archived_issues_leave_home_my_issues(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $active = $this->createIssue($workspace, $project, StatusCategory::InProgress);
        $active->assignees()->attach($owner);
        $archived = $this->createIssue($workspace, $project, StatusCategory::Done);
        $archived->assignees()->attach($owner);
        $archived->update(['archived_at' => now()]);

        $response = $this->actingAs($owner)->get('/');

        $response->assertInertia(
            fn (AssertableInertia $page) => $page
                ->has('myIssues', 1)
                ->where('myIssues.0.title', $active->title)
        );
    }

    public function test_archived_issues_leave_parent_candidates(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $issue = $this->createIssue($workspace, $project, StatusCategory::Backlog);
        $archived = $this->createIssue($workspace, $project, StatusCategory::Done);
        $archived->update(['archived_at' => now()]);

        $this->actingAs($owner)
            ->get("/issues/{$issue->id}")
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('candidates', 0)
            );
    }

    public function test_archives_view_lists_project_archived_issues_by_recency(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $oldest = $this->createIssue($workspace, $project, StatusCategory::Done);
        $oldest->update(['archived_at' => now()->subDays(3)]);
        $newest = $this->createIssue($workspace, $project, StatusCategory::Cancelled);
        $newest->update(['archived_at' => now()]);
        $middle = $this->createIssue($workspace, $project, StatusCategory::Done);
        $middle->update(['archived_at' => now()->subDay()]);
        $active = $this->createIssue($workspace, $project, StatusCategory::Backlog);

        $this->actingAs($owner)
            ->get("/projects/{$project->id}/archives")
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('issues', 3)
                    ->where('issues.0.id', $newest->id)
                    ->where('issues.1.id', $middle->id)
                    ->where('issues.2.id', $oldest->id)
            );
    }

    public function test_archives_view_only_shows_the_target_project_issues(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $otherProject = $workspace->projects()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Autre projet',
        ]);
        $here = $this->createIssue($workspace, $project, StatusCategory::Done);
        $here->update(['archived_at' => now()]);
        $there = $this->createIssue($workspace, $otherProject, StatusCategory::Done);
        $there->update(['archived_at' => now()]);

        $this->actingAs($owner)
            ->get("/projects/{$project->id}/archives")
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('issues', 1)
                    ->where('issues.0.id', $here->id)
            );
    }

    public function test_members_can_view_the_archives_list(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $member = $this->memberOf($workspace);
        $issue = $this->createIssue($workspace, $project, StatusCategory::Done);
        $issue->update(['archived_at' => now()]);

        $this->actingAs($member)
            ->get("/projects/{$project->id}/archives")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canUnarchive', false));

        $this->actingAs($owner)
            ->get("/projects/{$project->id}/archives")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canUnarchive', true));
    }

    public function test_archives_view_is_scoped_by_workspace(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $issue = $this->createIssue($workspace, $project, StatusCategory::Done);
        $issue->update(['archived_at' => now()]);

        $intruder = User::factory()->create();
        CreateWorkspace::run($intruder, ['name' => 'Theirs']);

        $this->actingAs($intruder)
            ->get("/projects/{$project->id}/archives")
            ->assertNotFound();
    }

    public function test_mcp_list_issues_excludes_archived_by_default(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $active = $this->createIssue($workspace, $project, StatusCategory::Backlog);
        $archived = $this->createIssue($workspace, $project, StatusCategory::Done);
        $archived->update(['archived_at' => now()]);

        $result = TaskrServer::actingAs($owner)
            ->tool(ListIssuesTool::class, ['workspace_id' => $workspace->id])
            ->assertOk()
            ->payload();

        $titles = collect($result['issues'])->pluck('title');

        $this->assertTrue($titles->contains($active->title));
        $this->assertFalse($titles->contains($archived->title));
        $this->assertSame(1, $result['total']);
    }

    public function test_mcp_list_issues_archived_include_and_only(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $active = $this->createIssue($workspace, $project, StatusCategory::Backlog);
        $archived = $this->createIssue($workspace, $project, StatusCategory::Done);
        $archived->update(['archived_at' => now()]);

        $included = TaskrServer::actingAs($owner)
            ->tool(ListIssuesTool::class, ['workspace_id' => $workspace->id, 'archived' => 'include'])
            ->assertOk()
            ->payload();
        $this->assertCount(2, $included['issues']);
        $this->assertSame(2, $included['total']);

        $only = TaskrServer::actingAs($owner)
            ->tool(ListIssuesTool::class, ['workspace_id' => $workspace->id, 'archived' => 'only'])
            ->assertOk()
            ->payload();
        $this->assertSame([$archived->title], collect($only['issues'])->pluck('title')->all());
        $this->assertSame(1, $only['total']);
    }

    public function test_mcp_list_issues_archived_rejects_unknown_values(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $this->createIssue($workspace, $project, StatusCategory::Backlog);

        TaskrServer::actingAs($owner)
            ->tool(ListIssuesTool::class, ['workspace_id' => $workspace->id, 'archived' => 'all'])
            ->assertHasErrors(['archived']);
    }
}

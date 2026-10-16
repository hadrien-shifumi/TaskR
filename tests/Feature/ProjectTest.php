<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Membership;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_create_project(): void
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);

        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        $this->actingAs($member)
            ->post('/projects', ['name' => 'Refonte site vitrine', 'description' => 'Premiere iteration'])
            ->assertRedirect(route('projects.index'));

        $this->assertDatabaseHas('projects', [
            'workspace_id' => $workspace->id,
            'name' => 'Refonte site vitrine',
            'archived_at' => null,
        ]);
    }

    public function test_project_names_are_unique_per_workspace(): void
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);

        app(WorkspaceContext::class)->set($workspace);
        CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);

        $this->actingAs($owner)
            ->post('/projects', ['name' => 'Site vitrine'])
            ->assertSessionHasErrors('name');
    }

    public function test_archive_is_reversible(): void
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);

        app(WorkspaceContext::class)->set($workspace);
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);

        $this->actingAs($owner)
            ->post("/projects/{$project->id}/archive")
            ->assertRedirect(route('projects.index'));

        $this->assertNotNull($project->fresh()->archived_at);

        $this->actingAs($owner)
            ->post("/projects/{$project->id}/archive")
            ->assertRedirect(route('projects.index'));

        $this->assertNull($project->fresh()->archived_at);
    }

    public function test_projects_page_splits_active_and_archived(): void
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);

        app(WorkspaceContext::class)->set($workspace);
        $active = CreateProject::run($workspace, $owner, ['name' => 'Active']);
        $archived = CreateProject::run($workspace, $owner, ['name' => 'Archived']);
        $archived->update(['archived_at' => now()]);

        $this->actingAs($owner)
            ->get('/projects')
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Projects/Index')
                    ->where('projects.active.0.id', $active->id)
                    ->where('projects.archived.0.id', $archived->id)
            );
    }

    public function test_projects_page_provides_archive_date_and_issue_count_for_archived_projects(): void
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);

        app(WorkspaceContext::class)->set($workspace);
        $project = CreateProject::run($workspace, $owner, ['name' => 'Sprint zéro']);
        $project->update(['archived_at' => now()->subDay()]);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Tâche 1']);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Tâche 2']);

        // The archived dialog shows "N issues · archived on <date>" per row.
        $this->actingAs($owner)
            ->get('/projects')
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Projects/Index')
                    ->where('projects.archived.0.id', $project->id)
                    ->where('projects.archived.0.archivedAt', $project->fresh()->archived_at->toISOString())
                    ->where('projects.archived.0.health.totalIssues', 2)
            );
    }

    public function test_member_of_another_workspace_cannot_access_projects(): void
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);

        app(WorkspaceContext::class)->set($workspace);
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);

        $intruder = User::factory()->create();
        CreateWorkspace::run($intruder, ['name' => 'Theirs']);

        // The intruder's context resolves to their own workspace, so the
        // scoped binding must not find the foreign project.
        $this->actingAs($intruder)
            ->patch("/projects/{$project->id}", ['name' => 'Hacked'])
            ->assertNotFound();
    }

    public function test_owner_can_update_project(): void
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);

        app(WorkspaceContext::class)->set($workspace);
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);

        $this->actingAs($owner)
            ->patch("/projects/{$project->id}", ['name' => 'Renamed', 'description' => 'Nouvelle desc'])
            ->assertRedirect(route('projects.index'));

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'name' => 'Renamed',
            'description' => 'Nouvelle desc',
        ]);
    }
}

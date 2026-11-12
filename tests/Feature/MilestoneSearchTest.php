<?php

namespace Tests\Feature;

use App\Actions\Milestones\CreateMilestone;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MilestoneSearchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace, 2: Project}
     */
    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);

        return [$owner, $workspace, $project];
    }

    public function test_milestones_search_finds_milestones_by_title_in_the_project(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $version = CreateMilestone::run($project, ['title' => 'Version 1'], $owner);
        CreateMilestone::run($project, ['title' => 'Sprint 1'], $owner);

        $this->actingAs($owner)
            ->getJson(route('milestones.search', ['project' => $project, 'q' => 'version']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $version->id)
            ->assertJsonPath('0.title', 'Version 1');
    }

    public function test_milestones_search_is_scoped_to_the_project(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        CreateMilestone::run($project, ['title' => 'Version 1'], $owner);

        $otherProject = CreateProject::run($workspace, $owner, ['name' => 'Appli mobile']);
        $foreign = CreateMilestone::run($otherProject, ['title' => 'Version 1'], $owner);

        $this->actingAs($owner)
            ->getJson(route('milestones.search', ['project' => $project, 'q' => 'version']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $project->milestones()->first()->id);
    }

    public function test_milestones_search_requires_a_query_and_caps_the_limit(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->getJson(route('milestones.search', ['project' => $project]))
            ->assertJsonValidationErrors(['q']);

        $this->actingAs($owner)
            ->getJson(route('milestones.search', ['project' => $project, 'q' => 'ok', 'limit' => 0]))
            ->assertJsonValidationErrors(['limit']);

        $this->actingAs($owner)
            ->getJson(route('milestones.search', ['project' => $project, 'q' => 'ok', 'limit' => 26]))
            ->assertJsonValidationErrors(['limit']);
    }

    public function test_milestones_search_is_scoped_to_the_guest_perimeter(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        CreateMilestone::run($project, ['title' => 'Version 1'], $owner);

        $otherProject = CreateProject::run($workspace, $owner, ['name' => 'Beta']);
        CreateMilestone::run($otherProject, ['title' => 'Version 1'], $owner);

        $guest = User::factory()->create();
        $membership = Membership::create([
            'user_id' => $guest->id,
            'workspace_id' => $workspace->id,
            'role' => 'guest',
        ]);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $guest->id,
            'role' => 'member',
        ]);

        $this->actingAs($guest)
            ->getJson(route('milestones.search', ['project' => $otherProject, 'q' => 'version']))
            ->assertNotFound();
    }
}

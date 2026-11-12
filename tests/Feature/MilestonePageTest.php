<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Milestones\CreateMilestone;
use App\Actions\Milestones\SetIssueMilestone;
use App\Actions\Milestones\SetMilestoneParent;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\StatusCategory;
use App\Models\Membership;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class MilestonePageTest extends TestCase
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

    /**
     * A milestone carrying one done and one backlog issue, plus a child
     * milestone — the tab's tree and progression fixtures.
     *
     * @return array{0: User, 1: Workspace, 2: Project, 3: Milestone, 4: Milestone}
     */
    private function setupTree(): array
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $milestone = CreateMilestone::run($project, ['title' => 'Version 1'], $owner);
        $child = CreateMilestone::run($project, ['title' => 'Sous-échéance'], $owner);
        SetMilestoneParent::run($child, $milestone->id, $owner);

        $doneStatus = $workspace->statuses()->where('category', StatusCategory::Done)->firstOrFail();
        $done = CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'Terminé',
            'status_id' => $doneStatus->id,
        ]);
        $open = CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'En cours',
        ]);

        SetIssueMilestone::run($done, $milestone->id, $owner);
        SetIssueMilestone::run($open, $milestone->id, $owner);

        return [$owner, $workspace, $project, $milestone, $child];
    }

    // ── Onglet Échéances ────────────────────────────────────────────

    public function test_projects_milestones_tab_renders_cards_with_parent_and_progress(): void
    {
        [$owner, $workspace, $project, $milestone, $child] = $this->setupTree();

        $this->actingAs($owner)
            ->get(route('projects.milestones', $project))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Milestones')
                ->where('project.id', $project->id)
                ->has('milestones', 2)
                ->where('milestones.0.id', $child->id)
                ->where('milestones.0.parentId', $milestone->id)
                ->where('milestones.0.totalIssues', 0)
                ->where('milestones.0.doneIssues', 0)
                ->where('milestones.1.id', $milestone->id)
                ->where('milestones.1.parentId', null)
                ->where('milestones.1.totalIssues', 2)
                ->where('milestones.1.doneIssues', 1)
                ->where('canContribute', true)
                ->where('canManage', true));
    }

    public function test_projects_milestones_tab_flags_permissions_for_a_guest(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $guest = User::factory()->create();
        Membership::create([
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
            ->get(route('projects.milestones', $project))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canContribute', false)
                ->where('canManage', false));
    }

    public function test_projects_milestones_tab_is_404_out_of_perimeter(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $outOfScope = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

        $guest = User::factory()->create();
        Membership::create([
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
            ->get(route('projects.milestones', $outOfScope))
            ->assertNotFound();
    }

    // ── Page de détail ──────────────────────────────────────────────

    public function test_milestones_show_page_renders_issues_children_and_stats(): void
    {
        [$owner, $workspace, $project, $milestone, $child] = $this->setupTree();

        $this->actingAs($owner)
            ->get(route('milestones.show', $milestone))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Milestones/Show')
                ->where('milestone.id', $milestone->id)
                ->where('milestone.title', 'Version 1')
                ->where('milestone.totalIssues', 2)
                ->where('milestone.doneIssues', 1)
                ->has('milestone.issues', 2)
                ->has('milestone.children', 1)
                ->where('milestone.children.0.id', $child->id)
                ->where('project.id', $project->id)
                ->where('canContribute', true)
                ->where('canManage', true));
    }

    public function test_milestones_show_page_is_404_out_of_perimeter(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $otherProject = CreateProject::run($workspace, $owner, ['name' => 'Beta']);
        $milestone = CreateMilestone::run($otherProject, ['title' => 'V1'], $owner);

        $guest = User::factory()->create();
        Membership::create([
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
            ->get(route('milestones.show', $milestone))
            ->assertNotFound();
    }

    // ── Flux JSON ───────────────────────────────────────────────────

    public function test_milestones_json_feed_carries_parent_and_progress(): void
    {
        [$owner, $workspace, $project, $milestone] = $this->setupTree();

        $this->actingAs($owner)
            ->get(route('milestones.index', $project))
            ->assertOk()
            ->assertJsonPath('milestones.1.id', $milestone->id)
            ->assertJsonPath('milestones.1.parentId', null)
            ->assertJsonPath('milestones.1.totalIssues', 2)
            ->assertJsonPath('milestones.1.doneIssues', 1);
    }
}

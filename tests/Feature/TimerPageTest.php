<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\TimeTracking\StartTimer;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class TimerPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace, 2: Project, 3: Issue}
     */
    private function setupIssue(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);
        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Page de profil']);

        return [$owner, $workspace, $project, $issue];
    }

    public function test_shared_timers_prop_is_empty_without_an_active_timer(): void
    {
        [$owner, , $project] = $this->setupIssue();

        $this->actingAs($owner)
            ->get(route('projects.board', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Board')
                ->where('timers', []));
    }

    public function test_shared_timers_prop_lists_every_running_timer_on_the_board(): void
    {
        [$owner, $workspace, $project, $issue] = $this->setupIssue();

        StartTimer::run($issue, $owner);
        $this->travel(90)->minutes();

        $otherProject = CreateProject::run($workspace, $owner, ['name' => 'Autre projet']);
        $otherIssue = CreateIssue::run($workspace, ['project_id' => $otherProject->id, 'title' => 'Autre ticket']);
        StartTimer::run($otherIssue, $owner);
        $this->travel(30)->minutes();

        $this->actingAs($owner)
            ->get(route('projects.board', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Board')
                ->has('timers', 2)
                ->where('timers.0.issueId', $issue->id)
                ->where('timers.0.issueTitle', 'Page de profil')
                ->where('timers.0.startedAt', fn (string $startedAt) => $startedAt !== '')
                ->where('timers.0.elapsedHours', fn (float $elapsedHours) => abs($elapsedHours - 2.0) < 0.01)
                ->where('timers.1.issueId', $otherIssue->id)
                ->where('timers.1.elapsedHours', fn (float $elapsedHours) => abs($elapsedHours - 0.5) < 0.01));
    }

    public function test_shared_timers_prop_follows_the_running_timer_on_the_issue_page(): void
    {
        [$owner, , , $issue] = $this->setupIssue();

        StartTimer::run($issue, $owner);
        $this->travel(30)->minutes();

        $this->actingAs($owner)
            ->get(route('issues.show', $issue->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Issues/Show')
                ->has('timers', 1)
                ->where('timers.0.issueId', $issue->id)
                ->where('timers.0.issueTitle', 'Page de profil')
                ->where('timers.0.startedAt', fn (string $startedAt) => $startedAt !== ''));
    }

    public function test_the_shared_timers_prop_is_personal_to_the_user(): void
    {
        [$owner, $workspace, $project, $issue] = $this->setupIssue();

        StartTimer::run($issue, $owner);

        $colleague = User::factory()->create();
        Membership::create(['user_id' => $colleague->id, 'workspace_id' => $workspace->id, 'role' => 'member']);

        $this->actingAs($colleague)
            ->get(route('projects.board', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Board')
                ->where('timers', []));
    }
}

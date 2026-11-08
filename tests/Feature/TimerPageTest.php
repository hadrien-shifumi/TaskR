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

    public function test_shared_timer_prop_is_null_without_an_active_timer(): void
    {
        [$owner, , $project] = $this->setupIssue();

        $this->actingAs($owner)
            ->get(route('projects.board', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Board')
                ->where('timer', null));
    }

    public function test_shared_timer_prop_follows_the_running_timer_on_the_board(): void
    {
        [$owner, , $project, $issue] = $this->setupIssue();

        StartTimer::run($issue, $owner);
        $this->travel(90)->minutes();

        $this->actingAs($owner)
            ->get(route('projects.board', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Board')
                ->where('timer.issueId', $issue->id)
                ->where('timer.issueTitle', 'Page de profil')
                ->where('timer.startedAt', fn (string $startedAt) => $startedAt !== '')
                ->where('timer.elapsedHours', fn (float $elapsedHours) => abs($elapsedHours - 1.5) < 0.01));
    }

    public function test_shared_timer_prop_follows_the_running_timer_on_the_issue_page(): void
    {
        [$owner, , , $issue] = $this->setupIssue();

        StartTimer::run($issue, $owner);
        $this->travel(30)->minutes();

        $this->actingAs($owner)
            ->get(route('issues.show', $issue->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Issues/Show')
                ->where('timer.issueId', $issue->id)
                ->where('timer.issueTitle', 'Page de profil')
                ->where('timer.startedAt', fn (string $startedAt) => $startedAt !== ''));
    }

    public function test_the_shared_timer_prop_is_personal_to_the_user(): void
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
                ->where('timer', null));
    }
}

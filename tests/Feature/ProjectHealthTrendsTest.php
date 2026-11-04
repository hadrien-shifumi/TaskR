<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Issues\MoveIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ProjectHealthTrendsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private Project $project;

    /** @var array<string, Status> */
    private array $statuses;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = CreateWorkspace::run($this->owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($this->workspace);
        $this->project = CreateProject::run($this->workspace, $this->owner, ['name' => 'Site vitrine']);

        $this->statuses = [];
        foreach ($this->workspace->statuses as $status) {
            $this->statuses[$status->category->value] = $status;
        }
    }

    private function createIssue(string $category, string $title = 'Issue'): Issue
    {
        return CreateIssue::run($this->workspace, [
            'project_id' => $this->project->id,
            'title' => $title,
            'status_id' => $this->statuses[$category]->id,
        ]);
    }

    // ── Manager view ─────────────────────────────────────────────────

    public function test_health_page_passes_trends_with_the_default_period(): void
    {
        $this->createIssue('backlog', 'Open today');
        $closed = $this->createIssue('backlog', 'Closed today');
        MoveIssue::run($closed, $this->statuses['done']->id);

        $this->actingAs($this->owner)
            ->get(route('projects.health', $this->project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Health')
                ->where('trends.period', '30')
                ->has('trends.points', 30)
                ->where('trends.points.29.date', now()->toDateString())
                ->where('trends.points.29.opened', 2)
                ->where('trends.points.29.closed', 1)
                ->where('trends.points.29.moved', 1)
                ->where('trends.journalSince', now()->toDateString()));
    }

    public function test_health_page_trends_follow_the_period_query_param(): void
    {
        $this->createIssue('backlog');

        $this->actingAs($this->owner)
            ->get(route('projects.health', ['project' => $this->project->id, 'period' => '90']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Health')
                ->where('trends.period', '90')
                ->has('trends.points', 90));

        $this->actingAs($this->owner)
            ->get(route('projects.health', ['project' => $this->project->id, 'period' => 'all']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('trends.period', 'all')
                ->has('trends.points', 1));
    }

    public function test_health_page_rejects_an_unknown_period(): void
    {
        $this->actingAs($this->owner)
            ->getJson(route('projects.health', ['project' => $this->project->id, 'period' => '7']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('period');
    }

    // ── Guest perimeter ──────────────────────────────────────────────

    public function test_guest_does_not_receive_trends(): void
    {
        $guest = User::factory()->create();
        Membership::create([
            'user_id' => $guest->id,
            'workspace_id' => $this->workspace->id,
            'role' => 'guest',
        ]);
        ProjectMember::create([
            'project_id' => $this->project->id,
            'user_id' => $guest->id,
        ]);

        $this->createIssue('backlog', 'Visible work');

        $this->actingAs($guest)
            ->get(route('projects.health', $this->project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Health')
                ->where('canSeeGlobalKpis', false)
                ->where('trends', null)
                ->where('health', null));
    }
}

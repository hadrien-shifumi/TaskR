<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class IssueDependencyListTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace, 2: Project, 3: array<string, Status>}
     */
    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);

        $statuses = [];
        foreach ($workspace->statuses as $status) {
            $statuses[$status->category->value] = $status;
        }

        return [$owner, $workspace, $project, $statuses];
    }

    private function makeIssue(Workspace $workspace, Project $project, string $title, ?Status $status = null): Issue
    {
        return CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => $title,
            ...($status === null ? [] : ['status_id' => $status->id]),
        ]);
    }

    /**
     * One open blocker X, B blocked by X, U blocked only by a done issue,
     * F without any dependency.
     *
     * @param  array<string, Status>  $statuses
     * @return array{blocker: Issue, blocked: Issue, unblockable: Issue, free: Issue}
     */
    private function makeDependencyFixtures(Workspace $workspace, Project $project, array $statuses): array
    {
        $blocker = $this->makeIssue($workspace, $project, 'Blocker ouvert', $statuses['backlog']);

        $blocked = $this->makeIssue($workspace, $project, 'Bloquee', $statuses['backlog']);
        $blocked->blockedBy()->sync([$blocker->id]);

        $doneBlocker = $this->makeIssue($workspace, $project, 'Blocker fini', $statuses['done']);
        $unblockable = $this->makeIssue($workspace, $project, 'Deblocable', $statuses['backlog']);
        $unblockable->blockedBy()->sync([$doneBlocker->id]);

        $free = $this->makeIssue($workspace, $project, 'Libre', $statuses['backlog']);

        return ['blocker' => $blocker, 'blocked' => $blocked, 'unblockable' => $unblockable, 'free' => $free];
    }

    public function test_issues_page_blocked_filter(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspace();
        $fixtures = $this->makeDependencyFixtures($workspace, $project, $statuses);

        $this->actingAs($owner)
            ->get('/issues?blocked=1')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Issues/Index')
                ->has('issues', 1)
                ->where('issues.0.id', $fixtures['blocked']->id)
                ->where('issues.0.blocked', true)
                ->where('issues.0.unblockable', false)
                // The param echoes back in filters so the URL stays shareable.
                ->where('filters.blocked', '1'));
    }

    public function test_blocked_filter_combines_with_status_filter(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspace();
        $fixtures = $this->makeDependencyFixtures($workspace, $project, $statuses);

        $this->actingAs($owner)
            ->get("/issues?blocked=1&status={$statuses['backlog']->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 1)
                ->where('issues.0.id', $fixtures['blocked']->id)
                ->where('issues.0.blocked', true)
                ->where('issues.0.unblockable', false));

        $this->actingAs($owner)
            ->get("/issues?blocked=1&status={$statuses['done']->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 0));
    }

    public function test_list_cards_expose_blocked_and_unblockable_flags(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspace();
        $this->makeDependencyFixtures($workspace, $project, $statuses);

        // Open blocker: blocked.
        $this->actingAs($owner)
            ->get('/issues?q=Bloquee')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 1)
                ->where('issues.0.blocked', true)
                ->where('issues.0.unblockable', false));

        // Every blocker done: unblockable — the badge case blocked alone
        // cannot distinguish from "no dependencies".
        $this->actingAs($owner)
            ->get('/issues?q=Deblocable')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 1)
                ->where('issues.0.blocked', false)
                ->where('issues.0.unblockable', true));

        // No dependency: neither flag.
        $this->actingAs($owner)
            ->get('/issues?q=Libre')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 1)
                ->where('issues.0.blocked', false)
                ->where('issues.0.unblockable', false));
    }

    public function test_kanban_view_respects_blocked_filter(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspace();
        $this->makeDependencyFixtures($workspace, $project, $statuses);

        $this->actingAs($owner)
            ->get('/issues?view=kanban&blocked=1', [
                'X-Inertia-Partial-Component' => 'Issues/Index',
                'X-Inertia-Partial-Data' => 'projectBoards',
            ])
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Issues/Index')
                ->where('projectBoards.0.columns.0.issuesCount', 1)
                ->where('projectBoards.0.columns.0.issues.0.title', 'Bloquee')
                ->where('projectBoards.0.columns.0.issues.0.blocked', true)
                ->where('projectBoards.0.columns.0.issues.0.unblockable', false));
    }
}

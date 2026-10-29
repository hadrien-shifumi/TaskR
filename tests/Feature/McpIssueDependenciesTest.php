<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Mcp\Servers\TaskrServer;
use App\Mcp\Tools\GetIssue;
use App\Mcp\Tools\ListIssues;
use App\Mcp\Tools\SetIssueDependencies;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

class McpIssueDependenciesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TestResponse::macro('result', function (): array {
            /** @var TestResponse $this */
            return $this->response->toArray()['result'] ?? [];
        });

        TestResponse::macro('payload', function (): array {
            /** @var TestResponse $this */
            return $this->response->toArray()['result']['structuredContent'] ?? [];
        });
    }

    public function test_set_issue_dependencies_creates_links(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $otherProject = CreateProject::run($workspace, $owner, ['name' => 'Secondaire']);
        app(WorkspaceContext::class)->set($workspace);

        // Cross-project links are legitimate inside the workspace.
        $blocker = CreateIssue::run($workspace, ['project_id' => $otherProject->id, 'title' => 'Blocker']);
        $blocked = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Blocked']);

        $result = $this->syncBlockers($owner, $workspace, $blocked, [$blocker->id])->assertOk()->payload();

        $this->assertSame([$blocker->id], $result['issue']['blockedByIds']);
        $this->assertSame([], $result['issue']['blocksIds']);
        $this->assertDatabaseHas('issue_dependencies', ['issue_id' => $blocked->id, 'blocked_by_id' => $blocker->id]);

        // A second call replaces the whole blocker set. The context is
        // re-set because every tool call flushes it on its way out.
        app(WorkspaceContext::class)->set($workspace);
        $second = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Second blocker']);
        $result = $this->syncBlockers($owner, $workspace, $blocked, [$second->id])->assertOk()->payload();

        $this->assertSame([$second->id], $result['issue']['blockedByIds']);
        $this->assertDatabaseMissing('issue_dependencies', ['issue_id' => $blocked->id, 'blocked_by_id' => $blocker->id]);
        $this->assertDatabaseHas('issue_dependencies', ['issue_id' => $blocked->id, 'blocked_by_id' => $second->id]);

        // An empty array removes every blocker.
        $result = $this->syncBlockers($owner, $workspace, $blocked, [])->assertOk()->payload();

        $this->assertSame([], $result['issue']['blockedByIds']);
        $this->assertDatabaseCount('issue_dependencies', 0);
    }

    public function test_set_issue_dependencies_refuses_cycle(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        app(WorkspaceContext::class)->set($workspace);

        $a = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'A']);
        $b = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'B']);
        $a->blockedBy()->sync([$b->id]);

        $response = $this->syncBlockers($owner, $workspace, $b, [$a->id]);

        $this->assertSame('business_rule', $response->payload()['error']);
        $this->assertStringContainsStringIgnoringCase('cycle', $response->payload()['message']);

        // The pre-existing edge is intact, the refused one was not written.
        $this->assertDatabaseHas('issue_dependencies', ['issue_id' => $a->id, 'blocked_by_id' => $b->id]);
        $this->assertDatabaseMissing('issue_dependencies', ['issue_id' => $b->id, 'blocked_by_id' => $a->id]);
    }

    public function test_set_issue_dependencies_refuses_self_block(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        app(WorkspaceContext::class)->set($workspace);

        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Self']);

        $response = $this->syncBlockers($owner, $workspace, $issue, [$issue->id]);

        $this->assertSame('business_rule', $response->payload()['error']);
        $this->assertStringContainsStringIgnoringCase('cannot block itself', $response->payload()['message']);
        $this->assertDatabaseCount('issue_dependencies', 0);
    }

    public function test_set_issue_dependencies_guest_perimeter(): void
    {
        [$owner, $workspace, $inScope, $outOfScope, $inScopeIssue, $outOfScopeIssue, $guest] = $this->setupScopedGuest();

        // A blocker outside the guest's perimeter is refused with a field error.
        $response = $this->syncBlockers($guest, $workspace, $inScopeIssue, [$outOfScopeIssue->id]);
        $payload = $response->payload();

        $this->assertSame('validation_failed', $payload['error']);
        $this->assertArrayHasKey('blocked_by', $payload['fields']);
        $this->assertStringContainsStringIgnoringCase('outside your project scope', $payload['fields']['blocked_by'][0]);
        $this->assertDatabaseCount('issue_dependencies', 0);

        // The out-of-perimeter issue itself is not found: no existence leak.
        $response = $this->syncBlockers($guest, $workspace, $outOfScopeIssue, []);

        $this->assertSame('not_found', $response->payload()['error']);
        $this->assertDatabaseCount('issue_dependencies', 0);

        // Within the perimeter the guest may link blockers.
        app(WorkspaceContext::class)->set($workspace);
        $inScopeBlocker = CreateIssue::run($workspace, ['project_id' => $inScope->id, 'title' => 'In scope blocker']);
        $result = $this->syncBlockers($guest, $workspace, $inScopeIssue, [$inScopeBlocker->id])->assertOk()->payload();

        $this->assertSame([$inScopeBlocker->id], $result['issue']['blockedByIds']);
    }

    public function test_list_issues_blocked_filter(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        app(WorkspaceContext::class)->set($workspace);

        $statuses = [];
        foreach ($workspace->statuses as $status) {
            $statuses[$status->category->value] = $status;
        }

        $target = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Blocked one']);
        $free = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Free one']);
        $openBlocker = CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'Open blocker',
            'status_id' => $statuses['in_progress']->id,
        ]);
        $closedBlocker = CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'Closed blocker',
            'status_id' => $statuses['done']->id,
        ]);

        $target->blockedBy()->sync([$openBlocker->id, $closedBlocker->id]);

        $blocked = TaskrServer::actingAs($owner)->tool(ListIssues::class, [
            'workspace_id' => $workspace->id,
            'blocked' => true,
        ])->assertOk()->payload();

        $this->assertSame(1, $blocked['total']);
        $this->assertSame([$target->id], array_column($blocked['issues'], 'id'));

        // Once every blocker is closed the issue leaves the filter.
        $openBlocker->update(['status_id' => $statuses['done']->id]);

        $blocked = TaskrServer::actingAs($owner)->tool(ListIssues::class, [
            'workspace_id' => $workspace->id,
            'blocked' => true,
        ])->assertOk()->payload();

        $this->assertSame(0, $blocked['total']);

        // Without the filter both non-blocked issues still appear.
        $all = TaskrServer::actingAs($owner)->tool(ListIssues::class, [
            'workspace_id' => $workspace->id,
        ])->assertOk()->payload();

        $this->assertContains($target->id, array_column($all['issues'], 'id'));
        $this->assertContains($free->id, array_column($all['issues'], 'id'));
    }

    public function test_get_issue_exposes_blocked_by_and_blocks(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        app(WorkspaceContext::class)->set($workspace);

        $blocker = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Open blocker']);
        $target = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Target']);
        $dependent = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Dependent']);

        $target->blockedBy()->sync([$blocker->id]);
        $dependent->blockedBy()->sync([$target->id]);

        $issue = TaskrServer::actingAs($owner)->tool(GetIssue::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $target->id,
        ])->assertOk()->payload()['issue'];

        $this->assertSame([$blocker->id], array_column($issue['blockedBy'], 'id'));
        $this->assertSame('Backlog', $issue['blockedBy'][0]['statusName']);
        $this->assertSame('backlog', $issue['blockedBy'][0]['statusCategory']);
        $this->assertSame([$dependent->id], array_column($issue['blocks'], 'id'));
        $this->assertTrue($issue['isBlocked']);
        $this->assertFalse($issue['isUnblockable']);
    }

    /**
     * @param  array<int, string>  $blockedBy
     */
    private function syncBlockers(User $user, Workspace $workspace, Issue $issue, array $blockedBy): TestResponse
    {
        return TaskrServer::actingAs($user)->tool(SetIssueDependencies::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $issue->id,
            'blocked_by' => $blockedBy,
        ]);
    }

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
     * Guest scoped to one project, with a second project out of reach.
     *
     * @return array{0: User, 1: Workspace, 2: Project, 3: Project, 4: Issue, 5: Issue, 6: User}
     */
    private function setupScopedGuest(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $inScope = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $outOfScope = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

        $inScopeIssue = CreateIssue::run($workspace, ['project_id' => $inScope->id, 'title' => 'Dans le perimetre']);
        $outOfScopeIssue = CreateIssue::run($workspace, ['project_id' => $outOfScope->id, 'title' => 'Hors perimetre']);

        $guest = User::factory()->create();
        Membership::create([
            'user_id' => $guest->id,
            'workspace_id' => $workspace->id,
            'role' => 'guest',
        ]);
        ProjectMember::create([
            'project_id' => $inScope->id,
            'user_id' => $guest->id,
            'role' => 'member',
        ]);

        return [$owner, $workspace, $inScope, $outOfScope, $inScopeIssue, $outOfScopeIssue, $guest];
    }
}

<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Milestones\CreateMilestone;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Mcp\Servers\TaskrServer;
use App\Mcp\Tools\GetProjectHealth;
use App\Mcp\Tools\ListMilestones;
use App\Models\Membership;
use App\Models\ProjectMember;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

class McpMilestonesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TestResponse::macro('payload', function (): array {
            /** @var TestResponse $this */
            return $this->response->toArray()['result']['structuredContent'] ?? [];
        });
    }

    private function setupOwner(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $alpha = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $beta = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

        return [$owner, $workspace, $alpha, $beta];
    }

    private function setupScopedGuest(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $inScope = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $outOfScope = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

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

        return [$owner, $workspace, $inScope, $outOfScope, $guest];
    }

    public function test_owner_without_filter_lists_milestones_across_projects_sorted_by_title(): void
    {
        [$owner, $workspace, $alpha, $beta] = $this->setupOwner();

        CreateMilestone::run($alpha, ['title' => 'Zeta sprint', 'description' => 'A'], $owner);
        CreateMilestone::run($alpha, ['title' => 'Alpha sprint'], $owner);
        CreateMilestone::run($beta, ['title' => 'Beta sprint'], $owner);

        $payload = TaskrServer::actingAs($owner)->tool(ListMilestones::class, [
            'workspace_id' => $workspace->id,
        ])->assertOk()->payload();

        $titles = array_column($payload['milestones'], 'title');
        $this->assertSame(['Alpha sprint', 'Beta sprint', 'Zeta sprint'], $titles);

        $this->assertSame([
            'id', 'title', 'description', 'projectId', 'parentId', 'startDate',
            'targetDate', 'completedAt', 'overdue', 'creatorName', 'totalIssues', 'doneIssues',
        ], array_keys($payload['milestones'][0]));
    }

    public function test_owner_with_project_filter_lists_only_that_project_milestones(): void
    {
        [$owner, $workspace, $alpha, $beta] = $this->setupOwner();

        CreateMilestone::run($alpha, ['title' => 'Alpha sprint'], $owner);
        CreateMilestone::run($beta, ['title' => 'Beta sprint'], $owner);

        $payload = TaskrServer::actingAs($owner)->tool(ListMilestones::class, [
            'workspace_id' => $workspace->id,
            'project_id' => $alpha->id,
        ])->assertOk()->payload();

        $this->assertCount(1, $payload['milestones']);
        $this->assertSame('Alpha sprint', $payload['milestones'][0]['title']);
        $this->assertSame($alpha->id, $payload['milestones'][0]['projectId']);
    }

    public function test_project_without_milestones_returns_empty_list(): void
    {
        [$owner, $workspace, $alpha] = $this->setupOwner();

        $payload = TaskrServer::actingAs($owner)->tool(ListMilestones::class, [
            'workspace_id' => $workspace->id,
            'project_id' => $alpha->id,
        ])->assertOk()->payload();

        $this->assertSame([], $payload['milestones']);
    }

    public function test_guest_without_filter_lists_only_milestones_of_in_scope_project(): void
    {
        [$owner, $workspace, $inScope, $outOfScope, $guest] = $this->setupScopedGuest();

        CreateMilestone::run($inScope, ['title' => 'In-scope sprint'], $owner);
        CreateMilestone::run($outOfScope, ['title' => 'Out-of-scope sprint'], $owner);

        $payload = TaskrServer::actingAs($guest)->tool(ListMilestones::class, [
            'workspace_id' => $workspace->id,
        ])->assertOk()->payload();

        $titles = array_column($payload['milestones'], 'title');
        $this->assertSame(['In-scope sprint'], $titles);
    }

    public function test_guest_with_out_of_scope_project_id_is_not_found(): void
    {
        [$owner, $workspace, , $outOfScope, $guest] = $this->setupScopedGuest();

        $response = TaskrServer::actingAs($guest)->tool(ListMilestones::class, [
            'workspace_id' => $workspace->id,
            'project_id' => $outOfScope->id,
        ]);

        $this->assertSame('not_found', $response->payload()['error']);
    }

    public function test_owner_health_exposes_milestones_block_and_keeps_existing_health_keys(): void
    {
        [$owner, $workspace, $alpha] = $this->setupOwner();

        $statuses = [];
        foreach ($workspace->statuses as $status) {
            $statuses[$status->category->value] = $status;
        }

        $milestone = CreateMilestone::run($alpha, [
            'title' => 'Launch',
            'start_date' => '2026-01-01',
            'target_date' => '2026-01-02',
        ], $owner);

        CreateIssue::run($workspace, [
            'project_id' => $alpha->id,
            'title' => 'Ship it',
            'status_id' => $statuses['done']->id,
            'milestone_id' => $milestone->id,
        ]);

        $payload = TaskrServer::actingAs($owner)->tool(GetProjectHealth::class, [
            'workspace_id' => $workspace->id,
            'project_id' => $alpha->id,
        ])->assertOk()->payload();

        $this->assertArrayHasKey('health', $payload);
        $this->assertArrayHasKey('totalIssues', $payload['health']);
        $this->assertArrayHasKey('completionPercentage', $payload['health']);
        $this->assertArrayHasKey('overdueIssues', $payload['health']);
        $this->assertArrayHasKey('estimatedHours', $payload['health']);
        $this->assertArrayHasKey('loggedHours', $payload['health']);
        $this->assertArrayHasKey('consumptionRatio', $payload['health']);
        $this->assertArrayHasKey('expectedPercentage', $payload['health']);
        $this->assertArrayHasKey('status', $payload['health']);
        $this->assertArrayHasKey('targetDate', $payload['health']);

        $this->assertArrayHasKey('milestones', $payload);
        $this->assertCount(1, $payload['milestones']);

        $row = $payload['milestones'][0];
        $this->assertSame('Launch', $row['title']);
        $this->assertSame('achieved', $row['status']);
        $this->assertSame(1, $row['totalIssues']);
        $this->assertSame(1, $row['doneIssues']);
        $this->assertSame(100.0, $row['realPercentage']);
        $this->assertNotNull($row['expectedPercentage']);
        $this->assertArrayHasKey('inLatePanel', $row);
    }

    public function test_member_health_omits_milestones_block_and_keeps_my_workload(): void
    {
        [$owner, $workspace, $alpha] = $this->setupScopedGuest();

        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);
        ProjectMember::create([
            'project_id' => $alpha->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);

        CreateMilestone::run($alpha, ['title' => 'Alpha sprint'], $owner);

        $payload = TaskrServer::actingAs($member)->tool(GetProjectHealth::class, [
            'workspace_id' => $workspace->id,
            'project_id' => $alpha->id,
        ])->assertOk()->payload();

        $this->assertArrayNotHasKey('milestones', $payload);
        $this->assertFalse($payload['canSeeGlobalKpis']);
        $this->assertArrayHasKey('myWorkload', $payload);
    }
}

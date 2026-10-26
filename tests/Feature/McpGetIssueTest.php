<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue as CreateIssueAction;
use App\Actions\Issues\SetIssueParent;
use App\Actions\Projects\CreateProject;
use App\Actions\TimeTracking\CreateTimeEntry;
use App\Actions\TimeTracking\SetIssueEstimate;
use App\Actions\Workspaces\CreateWorkspace;
use App\Mcp\Servers\TaskrServer;
use App\Mcp\Tools\GetIssue;
use App\Models\Label;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

class McpGetIssueTest extends TestCase
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

    public function test_get_issue_returns_the_full_ticket_body(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        app(WorkspaceContext::class)->set($workspace);

        $member = User::factory()->create();
        Membership::create(['user_id' => $member->id, 'workspace_id' => $workspace->id, 'role' => 'member']);
        $label = Label::create(['workspace_id' => $workspace->id, 'name' => 'agent-ready', 'color' => '#22c55e']);

        $issue = CreateIssueAction::run($workspace, [
            'project_id' => $project->id,
            'title' => 'Specification door',
            'description' => "## Context\nThe full body an agent must read.",
            'priority' => 'high',
            'due_date' => '2026-11-15',
            'assignees' => [$member->id],
            'label_ids' => [$label->id],
        ], $owner);
        SetIssueEstimate::run($issue, 6.0, $owner);
        CreateTimeEntry::run($issue, $owner, ['hours' => 1.5, 'note' => 'Research']);

        $parent = CreateIssueAction::run($workspace, ['project_id' => $project->id, 'title' => 'Epic parent'], $owner);
        SetIssueParent::run($issue, $parent->id, $owner);
        $sibling = CreateIssueAction::run($workspace, ['project_id' => $project->id, 'title' => 'Other child'], $owner);
        SetIssueParent::run($sibling, $parent->id, $owner);

        $result = TaskrServer::actingAs($owner)->tool(GetIssue::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $parent->id,
        ])->assertOk()->payload();

        $issuePayload = $result['issue'];
        $this->assertSame($parent->id, $issuePayload['id']);
        $this->assertSame('Epic parent', $issuePayload['title']);
        $this->assertSame($project->id, $issuePayload['project']['id']);
        $this->assertSame('Site vitrine', $issuePayload['project']['name']);
        $this->assertSame('Backlog', $issuePayload['status']['name']);
        $this->assertSame('backlog', $issuePayload['status']['category']);
        $this->assertSame('none', $issuePayload['priority']);
        $this->assertNull($issuePayload['dueDate']);
        $this->assertNull($issuePayload['estimatedHours']);
        $this->assertSame(0.0, $issuePayload['loggedHours']);
        $this->assertNull($issuePayload['remainingHours']);
        $this->assertSame([], $issuePayload['labels']);
        $this->assertSame([], $issuePayload['assignees']);
        $this->assertNull($issuePayload['parent']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $issuePayload['createdAt']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $issuePayload['updatedAt']);

        $childrenTitles = array_column($issuePayload['children'], 'title');
        sort($childrenTitles);
        $this->assertSame(['Other child', 'Specification door'], $childrenTitles);

        // The leaf carries the full qualification surface: description,
        // labels with names, assignees with names, hours and hierarchy.
        $leaf = TaskrServer::actingAs($owner)->tool(GetIssue::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $issue->id,
        ])->assertOk()->payload()['issue'];

        $this->assertSame("## Context\nThe full body an agent must read.", $leaf['description']);
        $this->assertSame('high', $leaf['priority']);
        $this->assertSame('2026-11-15', substr($leaf['dueDate'], 0, 10));
        $this->assertSame(6.0, $leaf['estimatedHours']);
        $this->assertSame(1.5, $leaf['loggedHours']);
        $this->assertSame(4.5, $leaf['remainingHours']);
        $this->assertSame([['id' => $label->id, 'name' => 'agent-ready', 'color' => '#22c55e']], $leaf['labels']);
        $this->assertSame([['id' => $member->id, 'name' => $member->name]], $leaf['assignees']);
        $this->assertSame($parent->id, $leaf['parent']['id']);
        $this->assertSame('Epic parent', $leaf['parent']['title']);
        $this->assertSame([], $leaf['children']);
    }

    public function test_get_issue_from_another_workspace_is_not_found(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $intruder = User::factory()->create();
        $theirs = CreateWorkspace::run($intruder, ['name' => 'Theirs']);
        app(WorkspaceContext::class)->set($theirs);
        $theirProject = CreateProject::run($theirs, $intruder, ['name' => 'Their project']);
        $theirIssue = CreateIssueAction::run($theirs, ['project_id' => $theirProject->id, 'title' => 'Secret', 'description' => 'Classified body'], $intruder);

        $response = TaskrServer::actingAs($owner)->tool(GetIssue::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $theirIssue->id,
        ]);

        $this->assertSame('not_found', $response->payload()['error']);
        $response->assertDontSee('Classified body');
    }

    public function test_get_issue_with_unknown_id_is_not_found(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $response = TaskrServer::actingAs($owner)->tool(GetIssue::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => (string) str()->ulid(),
        ]);

        $this->assertSame('not_found', $response->payload()['error']);
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
}

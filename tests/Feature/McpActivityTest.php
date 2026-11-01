<?php

namespace Tests\Feature;

use App\Actions\Audit\RecordActivity;
use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\AuditEventType;
use App\Mcp\Servers\TaskrServer;
use App\Mcp\Tools\ListActivity;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestListResponse;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

class McpActivityTest extends TestCase
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

        TestListResponse::macro('schemaFor', function (string $name): array {
            /** @var TestListResponse $this */
            $tool = collect($this->items)->firstWhere('name', $name) ?? [];

            return $tool['inputSchema']['properties'] ?? [];
        });

        TestListResponse::macro('inputSchemaFor', function (string $name): array {
            /** @var TestListResponse $this */
            $tool = collect($this->items)->firstWhere('name', $name) ?? [];

            return $tool['inputSchema'] ?? [];
        });

        TestListResponse::macro('namesFor', function (): array {
            /** @var TestListResponse $this */
            return collect($this->items)->pluck('name')->all();
        });
    }

    // ── Contrat du tool : schéma d'entrée ──────────────────────────────

    public function test_list_activity_schema_exposes_all_parameters(): void
    {
        [$owner] = $this->setupWorkspace();

        $properties = TaskrServer::actingAs($owner)->tools()->schemaFor('list_activity');
        $schema = TaskrServer::actingAs($owner)->tools()->inputSchemaFor('list_activity');

        $this->assertSame(
            ['workspace_id', 'issue_id', 'project_id', 'actor_id', 'type', 'limit'],
            array_keys($properties),
        );
        $this->assertSame(['workspace_id'], $schema['required'] ?? []);
    }

    // ── Lecture par ticket ─────────────────────────────────────────────

    public function test_list_activity_by_issue_returns_events_newest_first(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $issue = $this->createIssue($workspace, $project, 'Tracked', $owner);

        $first = RecordActivity::run(AuditEventType::IssueStatusChanged, $issue, $owner, [
            'status' => ['before' => 'backlog', 'after' => 'in_progress'],
        ]);
        $second = RecordActivity::run(AuditEventType::IssueAssigned, $issue, $owner, [
            'assignees' => ['before' => [], 'after' => [$owner->name]],
        ]);
        $third = RecordActivity::run(AuditEventType::IssueUpdated, $issue, $owner, [
            'title' => ['before' => 'Tracked', 'after' => 'Renamed'],
        ]);

        $result = TaskrServer::actingAs($owner)->tool(ListActivity::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $issue->id,
        ])->assertOk()->payload();

        $this->assertSame('issue', $result['subject']['type']);
        $this->assertSame($issue->id, $result['subject']['id']);

        // 3 recorded events + the issue.created event written by CreateIssue,
        // newest first (created_at desc, then id desc).
        $this->assertCount(4, $result['events']);
        $this->assertSame([$third->id, $second->id, $first->id], array_slice(array_column($result['events'], 'id'), 0, 3));

        $newest = $result['events'][0];
        $this->assertSame('issue.updated', $newest['type']);
        $this->assertSame($owner->id, $newest['actor']['id']);
        $this->assertSame($owner->name, $newest['actor']['name']);
        $this->assertSame(['title' => ['before' => 'Tracked', 'after' => 'Renamed']], $newest['changes']);
        $this->assertNotNull($newest['createdAt']);

        $oldest = $result['events'][3];
        $this->assertSame('issue.created', $oldest['type']);
    }

    public function test_list_activity_by_project_includes_issue_events(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $otherProject = CreateProject::run($workspace, $owner, ['name' => 'Autre projet']);

        $inProjectA = $this->createIssue($workspace, $project, 'Issue A', $owner);
        $inProjectB = $this->createIssue($workspace, $project, 'Issue B', $owner);
        $elsewhere = $this->createIssue($workspace, $otherProject, 'Issue elsewhere', $owner);

        $projectEvent = RecordActivity::run(AuditEventType::ProjectUpdated, $project, $owner, [
            'name' => ['before' => 'Site vitrine', 'after' => 'Site vitrine v2'],
        ]);
        $issueEvent = RecordActivity::run(AuditEventType::IssueStatusChanged, $inProjectB, $owner, [
            'status' => ['before' => 'backlog', 'after' => 'in_progress'],
        ]);
        $elsewhereEvent = RecordActivity::run(AuditEventType::IssueAssigned, $elsewhere, $owner);

        $result = TaskrServer::actingAs($owner)->tool(ListActivity::class, [
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
        ])->assertOk()->payload();

        $this->assertSame('project', $result['subject']['type']);
        $this->assertSame($project->id, $result['subject']['id']);

        // Issue events are denormalized onto project_id: they show up in the
        // project journal. The other project's issue event does not.
        $ids = array_column($result['events'], 'id');
        $this->assertContains($projectEvent->id, $ids);
        $this->assertContains($issueEvent->id, $ids);
        $this->assertNotContains($elsewhere->id, $ids);
        $this->assertNotContains($elsewhereEvent->id, $ids);

        // project.created (CreateProject) + 2 x issue.created (CreateIssue)
        // + project.updated + issue.status_changed, newest first.
        $this->assertCount(5, $result['events']);
        $this->assertSame([$issueEvent->id, $projectEvent->id], array_slice($ids, 0, 2));
    }

    // ── Filtres ────────────────────────────────────────────────────────

    public function test_list_activity_filters_by_actor(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        $issue = $this->createIssue($workspace, $project, 'Filtre acteur', $owner);
        $mine = RecordActivity::run(AuditEventType::IssueUpdated, $issue, $owner, [
            'title' => ['before' => 'Filtre acteur', 'after' => 'Filtre acteur v2'],
        ]);
        $theirs = RecordActivity::run(AuditEventType::IssueStatusChanged, $issue, $member, [
            'status' => ['before' => 'backlog', 'after' => 'in_progress'],
        ]);

        $result = TaskrServer::actingAs($owner)->tool(ListActivity::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $issue->id,
            'actor_id' => $member->id,
        ])->assertOk()->payload();

        $this->assertSame([$theirs->id], array_column($result['events'], 'id'));
        $this->assertSame($member->id, $result['events'][0]['actor']['id']);
        $this->assertNotContains($mine->id, array_column($result['events'], 'id'));
    }

    public function test_list_activity_filters_by_type(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $issue = $this->createIssue($workspace, $project, 'Filtre type', $owner);

        $statusChange = RecordActivity::run(AuditEventType::IssueStatusChanged, $issue, $owner, [
            'status' => ['before' => 'backlog', 'after' => 'in_progress'],
        ]);
        RecordActivity::run(AuditEventType::IssueAssigned, $issue, $owner);
        RecordActivity::run(AuditEventType::IssueUpdated, $issue, $owner);

        $result = TaskrServer::actingAs($owner)->tool(ListActivity::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $issue->id,
            'type' => 'issue.status_changed',
        ])->assertOk()->payload();

        $this->assertSame([$statusChange->id], array_column($result['events'], 'id'));
        $this->assertSame('issue.status_changed', $result['events'][0]['type']);
    }

    public function test_list_activity_respects_limit(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $issue = $this->createIssue($workspace, $project, 'Limité', $owner);

        $recorded = [];
        foreach (range(1, 5) as $index) {
            $recorded[] = RecordActivity::run(AuditEventType::IssueUpdated, $issue, $owner, [
                'title' => ['before' => 'Limité', 'after' => "Limité v{$index}"],
            ]);
        }

        $result = TaskrServer::actingAs($owner)->tool(ListActivity::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $issue->id,
            'limit' => 2,
        ])->assertOk()->payload();

        $this->assertCount(2, $result['events']);
        // The two newest events, in descending order.
        $this->assertSame(
            [$recorded[4]->id, $recorded[3]->id],
            array_column($result['events'], 'id'),
        );
    }

    // ── Rejets de validation ───────────────────────────────────────────

    public function test_list_activity_rejects_invalid_type(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $issue = $this->createIssue($workspace, $project, 'Type invalide', $owner);

        $response = TaskrServer::actingAs($owner)->tool(ListActivity::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $issue->id,
            'type' => 'issue.hacked',
        ]);

        $payload = $response->payload();
        $this->assertSame('validation_failed', $payload['error']);
        $this->assertArrayHasKey('type', $payload['fields']);
    }

    public function test_list_activity_requires_exactly_one_subject(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $issue = $this->createIssue($workspace, $project, 'Sujets', $owner);

        // Both subjects: refused.
        $both = TaskrServer::actingAs($owner)->tool(ListActivity::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $issue->id,
            'project_id' => $project->id,
        ])->payload();
        $this->assertSame('validation_failed', $both['error']);
        $this->assertArrayHasKey('fields', $both);

        // No subject at all: refused too.
        $none = TaskrServer::actingAs($owner)->tool(ListActivity::class, [
            'workspace_id' => $workspace->id,
        ])->payload();
        $this->assertSame('validation_failed', $none['error']);
        $this->assertArrayHasKey('fields', $none);
    }

    // ── Scoping guest ──────────────────────────────────────────────────

    public function test_list_activity_guest_reads_in_scope_issue_activity(): void
    {
        [$owner, $workspace, $inScope, , $guest] = $this->setupScopedGuest();
        $issue = $this->createIssue($workspace, $inScope, 'Visible', $owner);
        $event = RecordActivity::run(AuditEventType::IssueUpdated, $issue, $owner, [
            'title' => ['before' => 'Visible', 'after' => 'Visible v2'],
        ]);

        $result = TaskrServer::actingAs($guest)->tool(ListActivity::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $issue->id,
        ])->assertOk()->payload();

        // The recorded update plus the issue.created event written by
        // CreateIssue, newest first.
        $ids = array_column($result['events'], 'id');
        $this->assertCount(2, $ids);
        $this->assertSame($event->id, $ids[0]);
        $this->assertSame('issue.created', $result['events'][1]['type']);
    }

    public function test_list_activity_guest_issue_out_of_perimeter_is_not_found(): void
    {
        [$owner, $workspace, , $outOfScope, $guest] = $this->setupScopedGuest();
        $issue = $this->createIssue($workspace, $outOfScope, 'Secret', $owner);
        RecordActivity::run(AuditEventType::IssueUpdated, $issue, $owner);

        $response = TaskrServer::actingAs($guest)->tool(ListActivity::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $issue->id,
        ]);

        $this->assertSame('not_found', $response->payload()['error']);
        $response->assertDontSee('Secret');
    }

    public function test_list_activity_guest_project_out_of_perimeter_is_not_found(): void
    {
        [$owner, $workspace, , $outOfScope, $guest] = $this->setupScopedGuest();
        $issue = $this->createIssue($workspace, $outOfScope, 'Secret', $owner);
        RecordActivity::run(AuditEventType::IssueUpdated, $issue, $owner);

        $response = TaskrServer::actingAs($guest)->tool(ListActivity::class, [
            'workspace_id' => $workspace->id,
            'project_id' => $outOfScope->id,
        ]);

        $this->assertSame('not_found', $response->payload()['error']);
    }

    // ── Isolation cross-workspace ──────────────────────────────────────

    public function test_list_activity_is_cross_workspace_isolated(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $other = CreateWorkspace::run($owner, ['name' => 'Autre WS']);
        app(WorkspaceContext::class)->set($other);
        $otherProject = CreateProject::run($other, $owner, ['name' => 'Projet ailleurs']);
        $otherIssue = $this->createIssue($other, $otherProject, 'Ailleurs', $owner);
        RecordActivity::run(AuditEventType::IssueUpdated, $otherIssue, $owner);

        app(WorkspaceContext::class)->set($workspace);
        $this->createIssue($workspace, $project, 'Ici', $owner);

        // Foreign issue id, queried from our workspace.
        $issueResponse = TaskrServer::actingAs($owner)->tool(ListActivity::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $otherIssue->id,
        ]);
        $this->assertSame('not_found', $issueResponse->payload()['error']);

        // Foreign project id, queried from our workspace.
        $projectResponse = TaskrServer::actingAs($owner)->tool(ListActivity::class, [
            'workspace_id' => $workspace->id,
            'project_id' => $otherProject->id,
        ]);
        $this->assertSame('not_found', $projectResponse->payload()['error']);
    }

    // ── Journal append-only : aucun outil de mutation ──────────────────

    public function test_server_registers_no_audit_mutation_tool(): void
    {
        [$owner] = $this->setupWorkspace();

        $names = TaskrServer::actingAs($owner)->tools()->namesFor();

        $this->assertContains('list_activity', $names);

        $mutators = collect($names)
            ->reject(fn (string $name): bool => $name === 'list_activity')
            ->filter(fn (string $name): bool => preg_match('/activity|audit|event/', $name) === 1)
            ->values();

        $this->assertSame([], $mutators->all(), 'No audit event mutation tool may exist.');
    }

    // ── Helpers ────────────────────────────────────────────────────────

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
     * @return array{0: User, 1: Workspace, 2: Project, 3: Project, 4: User}
     */
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

    private function createIssue(Workspace $workspace, Project $project, string $title, User $actor): Issue
    {
        app(WorkspaceContext::class)->set($workspace);

        return CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => $title], $actor);
    }
}

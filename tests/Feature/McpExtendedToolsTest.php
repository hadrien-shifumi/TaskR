<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue as CreateIssueAction;
use App\Actions\Projects\CreateProject;
use App\Actions\TimeTracking\CreateTimeEntry;
use App\Actions\Workspaces\CreateWorkspace;
use App\Mcp\Servers\TaskrServer;
use App\Mcp\Tools\AddComment;
use App\Mcp\Tools\CreateIssue;
use App\Mcp\Tools\CreateLabel;
use App\Mcp\Tools\CreateStatus;
use App\Mcp\Tools\CreateTemplate;
use App\Mcp\Tools\CreateWorkspace as CreateWorkspaceTool;
use App\Mcp\Tools\DeleteComment;
use App\Mcp\Tools\DeleteKnowledge;
use App\Mcp\Tools\DeleteProject;
use App\Mcp\Tools\DeleteStatus;
use App\Mcp\Tools\DeleteTemplate;
use App\Mcp\Tools\DeleteTimeEntry;
use App\Mcp\Tools\GetProjectHealth;
use App\Mcp\Tools\InviteMember;
use App\Mcp\Tools\ListMembers;
use App\Mcp\Tools\ListTemplates;
use App\Mcp\Tools\RemoveMember;
use App\Mcp\Tools\ReorderStatuses;
use App\Mcp\Tools\SetIssueEstimate;
use App\Mcp\Tools\UpdateComment;
use App\Mcp\Tools\UpdateStatus;
use App\Mcp\Tools\UpdateTemplate;
use App\Mcp\Tools\UpdateTimeEntry;
use App\Models\IssueTemplate;
use App\Models\KnowledgePage;
use App\Models\Membership;
use App\Models\Project;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Testing\TestListResponse;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

class McpExtendedToolsTest extends TestCase
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
    }

    public function test_tools_catalog_exposes_all_21_new_tools(): void
    {
        TaskrServer::tools()->assertRegistered([
            // Phase 1 — Critiques
            DeleteProject::class,
            GetProjectHealth::class,
            DeleteKnowledge::class,
            SetIssueEstimate::class,
            UpdateTimeEntry::class,
            DeleteTimeEntry::class,
            UpdateComment::class,
            DeleteComment::class,
            // Phase 2 — Importants
            CreateWorkspaceTool::class,
            InviteMember::class,
            RemoveMember::class,
            ListMembers::class,
            CreateStatus::class,
            UpdateStatus::class,
            DeleteStatus::class,
            ReorderStatuses::class,
            CreateLabel::class,
            // Phase 3 — Utiles
            ListTemplates::class,
            CreateTemplate::class,
            UpdateTemplate::class,
            DeleteTemplate::class,
        ]);
    }

    // ─── Phase 1 — Critiques ────────────────────────────────────────────

    public function test_delete_project_removes_empty_project(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $result = TaskrServer::actingAs($owner)->tool(DeleteProject::class, [
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
        ])->assertOk()->payload();

        $this->assertTrue($result['deleted']);
        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    }

    public function test_delete_project_fails_when_project_has_issues(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        app(WorkspaceContext::class)->set($workspace);
        CreateIssueAction::run($workspace, ['project_id' => $project->id, 'title' => 'Existing']);

        TaskrServer::actingAs($owner)->tool(DeleteProject::class, [
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
        ])->assertHasErrors(['issue']);

        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    public function test_get_project_health_returns_indicators(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        app(WorkspaceContext::class)->set($workspace);
        CreateIssueAction::run($workspace, ['project_id' => $project->id, 'title' => 'Done issue']);

        $result = TaskrServer::actingAs($owner)->tool(GetProjectHealth::class, [
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
        ])->assertOk()->payload();

        $this->assertArrayHasKey('health', $result);
        $this->assertSame(1, $result['health']['totalIssues']);
    }

    public function test_delete_knowledge_removes_page(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();
        app(WorkspaceContext::class)->set($workspace);
        $page = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'title' => 'To delete',
            'body' => 'Body',
        ]);

        $result = TaskrServer::actingAs($owner)->tool(DeleteKnowledge::class, [
            'workspace_id' => $workspace->id,
            'page_id' => $page->id,
        ])->assertOk()->payload();

        $this->assertTrue($result['deleted']);

        // AgentTool clears the workspace context after each call.
        app(WorkspaceContext::class)->set($workspace);

        // Soft delete: the row remains with deleted_at set, hidden from
        // default queries, with trash metadata recorded.
        $this->assertNull(KnowledgePage::find($page->id));
        $trashed = KnowledgePage::withTrashed()->findOrFail($page->id);
        $this->assertNotNull($trashed->deleted_at);
        $this->assertSame($owner->id, (int) $trashed->deleted_by_id);
        $this->assertSame('manual', $trashed->deleted_reason);
    }

    public function test_delete_knowledge_fails_when_page_has_children(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();
        app(WorkspaceContext::class)->set($workspace);
        $parent = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'title' => 'Parent',
            'body' => 'Body',
        ]);
        KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'title' => 'Child',
            'body' => 'Body',
            'parent_id' => $parent->id,
        ]);

        TaskrServer::actingAs($owner)->tool(DeleteKnowledge::class, [
            'workspace_id' => $workspace->id,
            'page_id' => $parent->id,
        ])->assertHasErrors(['child']);

        $this->assertDatabaseHas('knowledge_pages', ['id' => $parent->id]);
    }

    public function test_set_issue_estimate_updates_issue(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        app(WorkspaceContext::class)->set($workspace);
        $issue = CreateIssueAction::run($workspace, ['project_id' => $project->id, 'title' => 'Timed']);

        $result = TaskrServer::actingAs($owner)->tool(SetIssueEstimate::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $issue->id,
            'estimated_hours' => 5.5,
        ])->assertOk()->payload();

        $this->assertSame(5.5, $result['issue']['estimatedHours'] ?? null);
        $this->assertDatabaseHas('issues', ['id' => $issue->id, 'estimated_hours' => 5.5]);
    }

    public function test_update_time_entry_by_author(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        app(WorkspaceContext::class)->set($workspace);
        $issue = CreateIssueAction::run($workspace, ['project_id' => $project->id, 'title' => 'Timed']);
        $entry = CreateTimeEntry::run($issue, $owner, ['hours' => 1.0, 'note' => 'Initial']);

        $result = TaskrServer::actingAs($owner)->tool(UpdateTimeEntry::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $issue->id,
            'time_entry_id' => $entry->id,
            'hours' => 2.5,
            'note' => 'Updated',
        ])->assertOk()->payload();

        $this->assertSame(2.5, $result['timeEntry']['hours']);
        $this->assertSame('Updated', $result['timeEntry']['note']);
    }

    public function test_update_time_entry_refused_for_non_author(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        app(WorkspaceContext::class)->set($workspace);
        $issue = CreateIssueAction::run($workspace, ['project_id' => $project->id, 'title' => 'Timed']);
        $entry = CreateTimeEntry::run($issue, $owner, ['hours' => 1.0]);

        $other = User::factory()->create();
        Membership::create([
            'user_id' => $other->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        TaskrServer::actingAs($other)->tool(UpdateTimeEntry::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $issue->id,
            'time_entry_id' => $entry->id,
            'hours' => 2.5,
        ])->assertHasErrors(['not the author']);
    }

    public function test_delete_time_entry_by_author(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        app(WorkspaceContext::class)->set($workspace);
        $issue = CreateIssueAction::run($workspace, ['project_id' => $project->id, 'title' => 'Timed']);
        $entry = CreateTimeEntry::run($issue, $owner, ['hours' => 1.0]);

        $result = TaskrServer::actingAs($owner)->tool(DeleteTimeEntry::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $issue->id,
            'time_entry_id' => $entry->id,
        ])->assertOk()->payload();

        $this->assertTrue($result['deleted']);
        $this->assertDatabaseMissing('time_entries', ['id' => $entry->id]);
    }

    public function test_update_comment_by_author(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        app(WorkspaceContext::class)->set($workspace);
        $issue = CreateIssueAction::run($workspace, ['project_id' => $project->id, 'title' => 'Target']);
        $comment = $issue->comments()->create([
            'author_id' => $owner->id,
            'body' => 'Original',
        ]);

        $result = TaskrServer::actingAs($owner)->tool(UpdateComment::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $issue->id,
            'comment_id' => $comment->id,
            'body' => 'Updated body',
        ])->assertOk()->payload();

        $this->assertSame('Updated body', $result['comment']['body']);
    }

    public function test_delete_comment_by_author(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        app(WorkspaceContext::class)->set($workspace);
        $issue = CreateIssueAction::run($workspace, ['project_id' => $project->id, 'title' => 'Target']);
        $comment = $issue->comments()->create([
            'author_id' => $owner->id,
            'body' => 'To delete',
        ]);

        $result = TaskrServer::actingAs($owner)->tool(DeleteComment::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $issue->id,
            'comment_id' => $comment->id,
        ])->assertOk()->payload();

        $this->assertTrue($result['deleted']);
        $this->assertDatabaseMissing('comments', ['id' => $comment->id]);
    }

    public function test_mcp_add_comment_rejects_missing_body(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        app(WorkspaceContext::class)->set($workspace);
        $issue = CreateIssueAction::run($workspace, ['project_id' => $project->id, 'title' => 'Target']);

        TaskrServer::actingAs($owner)->tool(AddComment::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $issue->id,
        ])->assertHasErrors(['body']);

        $this->assertDatabaseCount('comments', 0);
    }

    public function test_mcp_add_comment_rejects_body_over_20000_characters(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        app(WorkspaceContext::class)->set($workspace);
        $issue = CreateIssueAction::run($workspace, ['project_id' => $project->id, 'title' => 'Target']);

        TaskrServer::actingAs($owner)->tool(AddComment::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $issue->id,
            'body' => str_repeat('a', 20001),
        ])->assertHasErrors(['body']);

        $this->assertDatabaseCount('comments', 0);
    }

    public function test_mcp_update_comment_rejects_body_over_20000_characters(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        app(WorkspaceContext::class)->set($workspace);
        $issue = CreateIssueAction::run($workspace, ['project_id' => $project->id, 'title' => 'Target']);
        $comment = $issue->comments()->create([
            'author_id' => $owner->id,
            'body' => 'Original',
        ]);

        TaskrServer::actingAs($owner)->tool(UpdateComment::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $issue->id,
            'comment_id' => $comment->id,
            'body' => str_repeat('a', 20001),
        ])->assertHasErrors(['body']);

        $this->assertDatabaseHas('comments', [
            'id' => $comment->id,
            'body' => 'Original',
        ]);
    }

    // ─── Phase 2 — Importants ───────────────────────────────────────────

    public function test_create_workspace_creates_workspace_with_owner(): void
    {
        $owner = User::factory()->create();

        $result = TaskrServer::actingAs($owner)->tool(CreateWorkspaceTool::class, [
            'name' => 'New Workspace',
        ])->assertOk()->payload();

        $this->assertSame('New Workspace', $result['workspace']['name']);
        $this->assertSame('owner', $result['workspace']['role']);
        $this->assertDatabaseHas('workspaces', ['id' => $result['workspace']['id'], 'name' => 'New Workspace']);
    }

    public function test_invite_member_by_owner(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $result = TaskrServer::actingAs($owner)->tool(InviteMember::class, [
            'workspace_id' => $workspace->id,
            'email' => 'new@example.com',
        ])->assertOk()->payload();

        $this->assertSame('new@example.com', $result['invitation']['email']);
        $this->assertDatabaseHas('invitations', ['workspace_id' => $workspace->id, 'email' => 'new@example.com']);
    }

    public function test_invite_member_refused_for_non_owner(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        TaskrServer::actingAs($member)->tool(InviteMember::class, [
            'workspace_id' => $workspace->id,
            'email' => 'new@example.com',
        ])->assertHasErrors(['owner']);
    }

    public function test_remove_member_by_owner(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        $result = TaskrServer::actingAs($owner)->tool(RemoveMember::class, [
            'workspace_id' => $workspace->id,
            'user_id' => (string) $member->id,
        ])->assertOk()->payload();

        $this->assertTrue($result['removed']);
        $this->assertDatabaseMissing('memberships', [
            'workspace_id' => $workspace->id,
            'user_id' => $member->id,
        ]);
    }

    public function test_list_members_returns_members_and_invitations(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $result = TaskrServer::actingAs($owner)->tool(ListMembers::class, [
            'workspace_id' => $workspace->id,
        ])->assertOk()->payload();

        $this->assertCount(1, $result['members']);
        $this->assertSame($owner->email, $result['members'][0]['email']);
        $this->assertSame('owner', $result['members'][0]['role']);
    }

    public function test_create_status_by_owner(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $result = TaskrServer::actingAs($owner)->tool(CreateStatus::class, [
            'workspace_id' => $workspace->id,
            'name' => 'Review',
            'category' => 'in_progress',
        ])->assertOk()->payload();

        $this->assertSame('Review', $result['status']['name']);
        $this->assertSame('in_progress', $result['status']['category']);
        $this->assertDatabaseHas('statuses', ['id' => $result['status']['id'], 'name' => 'Review']);
    }

    public function test_create_status_refused_for_non_owner(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        TaskrServer::actingAs($member)->tool(CreateStatus::class, [
            'workspace_id' => $workspace->id,
            'name' => 'Review',
            'category' => 'in_progress',
        ])->assertHasErrors(['owner']);
    }

    public function test_update_status_by_owner(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $status = $workspace->statuses()->firstOrFail();

        $result = TaskrServer::actingAs($owner)->tool(UpdateStatus::class, [
            'workspace_id' => $workspace->id,
            'status_id' => $status->id,
            'name' => 'Renamed',
            'category' => 'backlog',
        ])->assertOk()->payload();

        $this->assertSame('Renamed', $result['status']['name']);
    }

    public function test_delete_status_by_owner(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        app(WorkspaceContext::class)->set($workspace);
        $status = Status::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Extra',
            'category' => 'backlog',
            'position' => 99,
        ]);

        $result = TaskrServer::actingAs($owner)->tool(DeleteStatus::class, [
            'workspace_id' => $workspace->id,
            'status_id' => $status->id,
        ])->assertOk()->payload();

        $this->assertTrue($result['deleted']);
        $this->assertDatabaseMissing('statuses', ['id' => $status->id]);
    }

    public function test_reorder_statuses_by_owner(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $statuses = $workspace->statuses()->orderBy('position')->get();
        $reversed = $statuses->reverse()->pluck('id')->all();

        $result = TaskrServer::actingAs($owner)->tool(ReorderStatuses::class, [
            'workspace_id' => $workspace->id,
            'ids' => $reversed,
        ])->assertOk()->payload();

        $this->assertTrue($result['reordered']);
    }

    public function test_create_label(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $result = TaskrServer::actingAs($owner)->tool(CreateLabel::class, [
            'workspace_id' => $workspace->id,
            'name' => 'Bug',
            'color' => 'red',
        ])->assertOk()->payload();

        $this->assertSame('Bug', $result['label']['name']);
        $this->assertSame('red', $result['label']['color']);
        $this->assertDatabaseHas('labels', ['id' => $result['label']['id'], 'name' => 'Bug']);
    }

    // ─── Phase 3 — Utiles ───────────────────────────────────────────────

    public function test_list_templates_returns_templates(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        app(WorkspaceContext::class)->set($workspace);
        IssueTemplate::create([
            'workspace_id' => $workspace->id,
            'name' => 'Bug Report',
            'description' => 'Template for bugs',
        ]);

        $result = TaskrServer::actingAs($owner)->tool(ListTemplates::class, [
            'workspace_id' => $workspace->id,
        ])->assertOk()->payload();

        $this->assertCount(1, $result['templates']);
        $this->assertSame('Bug Report', $result['templates'][0]['name']);
    }

    public function test_create_template_by_owner(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $result = TaskrServer::actingAs($owner)->tool(CreateTemplate::class, [
            'workspace_id' => $workspace->id,
            'name' => 'Feature Request',
            'description' => 'Template for features',
            'priority' => 'high',
        ])->assertOk()->payload();

        $this->assertSame('Feature Request', $result['template']['name']);
        $this->assertDatabaseHas('issue_templates', ['id' => $result['template']['id'], 'name' => 'Feature Request']);
    }

    public function test_create_template_refused_for_non_owner(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        TaskrServer::actingAs($member)->tool(CreateTemplate::class, [
            'workspace_id' => $workspace->id,
            'name' => 'Feature Request',
        ])->assertHasErrors(['owner']);
    }

    public function test_update_template_by_owner(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        app(WorkspaceContext::class)->set($workspace);
        $template = IssueTemplate::create([
            'workspace_id' => $workspace->id,
            'name' => 'Old Name',
        ]);

        $result = TaskrServer::actingAs($owner)->tool(UpdateTemplate::class, [
            'workspace_id' => $workspace->id,
            'template_id' => $template->id,
            'name' => 'New Name',
        ])->assertOk()->payload();

        $this->assertSame('New Name', $result['template']['name']);
    }

    public function test_delete_template_by_owner(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        app(WorkspaceContext::class)->set($workspace);
        $template = IssueTemplate::create([
            'workspace_id' => $workspace->id,
            'name' => 'To Delete',
        ]);

        $result = TaskrServer::actingAs($owner)->tool(DeleteTemplate::class, [
            'workspace_id' => $workspace->id,
            'template_id' => $template->id,
        ])->assertOk()->payload();

        $this->assertTrue($result['deleted']);
        $this->assertDatabaseMissing('issue_templates', ['id' => $template->id]);
    }

    public function test_create_issue_schema_exposes_template_parameter(): void
    {
        [$owner] = $this->setupWorkspace();

        $properties = TaskrServer::actingAs($owner)->tools()->schemaFor('create_issue');

        $this->assertArrayHasKey('template', $properties);
        $this->assertSame('string', $properties['template']['type'] ?? null);
    }

    public function test_create_issue_applies_template_defaults_via_mcp(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $bug = TaskrServer::actingAs($owner)->tool(CreateLabel::class, [
            'workspace_id' => $workspace->id,
            'name' => 'bug',
            'color' => 'red',
        ])->assertOk()->payload()['label']['id'];

        $front = TaskrServer::actingAs($owner)->tool(CreateLabel::class, [
            'workspace_id' => $workspace->id,
            'name' => 'front',
            'color' => 'blue',
        ])->assertOk()->payload()['label']['id'];

        $template = TaskrServer::actingAs($owner)->tool(CreateTemplate::class, [
            'workspace_id' => $workspace->id,
            'name' => 'Bug',
            'description' => '## Contexte',
            'priority' => 'high',
            'label_ids' => [$bug, $front],
        ])->assertOk()->payload()['template'];

        $result = TaskrServer::actingAs($owner)->tool(CreateIssue::class, [
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'From agent',
            'template' => $template['id'],
        ])->assertOk()->payload();

        $this->assertSame('## Contexte', $result['issue']['description']);
        $this->assertSame('high', $result['issue']['priority']);
        $this->assertSame([$bug, $front], $result['issue']['labelIds']);
    }

    public function test_create_issue_explicit_values_win_over_template_via_mcp(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $bug = TaskrServer::actingAs($owner)->tool(CreateLabel::class, [
            'workspace_id' => $workspace->id,
            'name' => 'bug',
            'color' => 'red',
        ])->assertOk()->payload()['label']['id'];

        $template = TaskrServer::actingAs($owner)->tool(CreateTemplate::class, [
            'workspace_id' => $workspace->id,
            'name' => 'Bug',
            'description' => '## Corps du gabarit',
            'priority' => 'high',
            'label_ids' => [$bug],
        ])->assertOk()->payload()['template'];

        $result = TaskrServer::actingAs($owner)->tool(CreateIssue::class, [
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'Explicit wins',
            'description' => 'Custom',
            'priority' => 'low',
            'template' => $template['id'],
        ])->assertOk()->payload();

        $this->assertSame('Custom', $result['issue']['description']);
        $this->assertSame('low', $result['issue']['priority']);
        $this->assertSame([$bug], $result['issue']['labelIds']);
    }

    public function test_create_issue_with_unknown_template_is_not_found(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $response = TaskrServer::actingAs($owner)->tool(CreateIssue::class, [
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'From agent',
            'template' => (string) Str::ulid(),
        ]);

        $this->assertSame('not_found', $response->payload()['error']);
        $this->assertDatabaseCount('issues', 0);
    }

    public function test_create_issue_with_template_of_another_workspace_is_not_found(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $other = CreateWorkspace::run($owner, ['name' => 'Other']);
        $foreign = TaskrServer::actingAs($owner)->tool(CreateTemplate::class, [
            'workspace_id' => $other->id,
            'name' => 'Foreign',
        ])->assertOk()->payload()['template'];

        $response = TaskrServer::actingAs($owner)->tool(CreateIssue::class, [
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'Intrusion',
            'template' => $foreign['id'],
        ]);

        $this->assertSame('not_found', $response->payload()['error']);
        $this->assertDatabaseCount('issues', 0);
    }

    // ─── Helpers ────────────────────────────────────────────────────────

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

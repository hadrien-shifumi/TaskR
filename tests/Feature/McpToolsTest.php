<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue as CreateIssueAction;
use App\Actions\Issues\ShowIssuesPage;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Mcp\Resources\ProjectIssues;
use App\Mcp\Servers\TaskrServer;
use App\Mcp\Tools\AddComment;
use App\Mcp\Tools\ArchiveProject;
use App\Mcp\Tools\CreateIssue;
use App\Mcp\Tools\CreateProject as CreateProjectTool;
use App\Mcp\Tools\DeleteIssue;
use App\Mcp\Tools\ListComments;
use App\Mcp\Tools\ListIssues;
use App\Mcp\Tools\ListLabels;
use App\Mcp\Tools\ListProjects;
use App\Mcp\Tools\ListStatuses;
use App\Mcp\Tools\ListTimeEntries;
use App\Mcp\Tools\ListWorkspaces as ListWorkspacesTool;
use App\Mcp\Tools\LogTime;
use App\Mcp\Tools\MoveIssue;
use App\Mcp\Tools\SetAssignees;
use App\Mcp\Tools\SetIssueParent;
use App\Mcp\Tools\SetLabels;
use App\Mcp\Tools\UpdateIssue;
use App\Mcp\Tools\UpdateProject as UpdateProjectTool;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

class McpToolsTest extends TestCase
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

    public function test_tools_catalog_exposes_expected_tools(): void
    {
        TaskrServer::tools()->assertRegistered([
            ListWorkspacesTool::class, ListProjects::class, CreateProjectTool::class, UpdateProjectTool::class, ArchiveProject::class,
            ListIssues::class, CreateIssue::class, UpdateIssue::class,
            DeleteIssue::class, MoveIssue::class,
            SetIssueParent::class, SetAssignees::class, SetLabels::class,
            AddComment::class, ListComments::class,
            ListStatuses::class, ListLabels::class,
            LogTime::class, ListTimeEntries::class,
        ]);
    }

    public function test_list_workspaces_returns_member_workspaces_with_role(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $intruder = User::factory()->create();
        CreateWorkspace::run($intruder, ['name' => 'Theirs']);

        $result = TaskrServer::actingAs($owner)->tool(ListWorkspacesTool::class)->assertOk()->payload();

        $this->assertCount(1, $result['workspaces']);
        $this->assertSame($workspace->id, $result['workspaces'][0]['id']);
        $this->assertSame('owner', $result['workspaces'][0]['role']);
    }

    public function test_tool_call_on_non_member_workspace_is_refused_without_leak(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $intruder = User::factory()->create();
        CreateWorkspace::run($intruder, ['name' => 'Theirs']);

        TaskrServer::actingAs($intruder)
            ->tool(ListIssues::class, ['workspace_id' => $workspace->id])
            ->assertHasErrors(['You are not a member of this workspace.'])
            ->assertDontSee($workspace->name);

        // Non-existent workspace ids yield the same refusal: no existence leak.
        TaskrServer::actingAs($intruder)
            ->tool(ListIssues::class, ['workspace_id' => (string) str()->ulid()])
            ->assertHasErrors(['You are not a member of this workspace.']);
    }

    public function test_create_issue_creates_issue_visible_in_ui(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $status = $workspace->statuses()->firstOrFail();

        $result = TaskrServer::actingAs($owner)->tool(CreateIssue::class, [
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'From agent',
            'status_id' => $status->id,
            'priority' => 'high',
        ])->assertOk()->payload();

        $issueId = $result['issue']['id'];
        $this->assertMatchesRegularExpression('/^[0-9a-hjkmnp-tv-z]{26}$/i', $issueId);

        $this->assertDatabaseHas('issues', [
            'id' => $issueId,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'From agent',
            'priority' => 'high',
        ]);

        // Visible through the UI read path.
        app(WorkspaceContext::class)->set($workspace);
        $page = ShowIssuesPage::run($workspace, []);
        $this->assertSame('From agent', $page['issues'][0]['title']);
    }

    public function test_create_issue_with_empty_title_returns_actionable_validation_error(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $result = TaskrServer::actingAs($owner)->tool(CreateIssue::class, [
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => '',
        ])->assertHasErrors()->payload();

        $this->assertSame('validation_failed', $result['error']);
        $this->assertArrayHasKey('title', $result['fields']);

        // The session survives: a corrected call succeeds.
        TaskrServer::actingAs($owner)->tool(CreateIssue::class, [
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'Fixed',
        ])->assertOk();
    }

    public function test_update_issue_from_another_workspace_is_not_found(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $intruder = User::factory()->create();
        $theirs = CreateWorkspace::run($intruder, ['name' => 'Theirs']);
        app(WorkspaceContext::class)->set($theirs);
        $theirProject = CreateProject::run($theirs, $intruder, ['name' => 'Their project']);
        $theirIssue = CreateIssueAction::run($theirs, ['project_id' => $theirProject->id, 'title' => 'Theirs']);

        TaskrServer::actingAs($owner)
            ->tool(UpdateIssue::class, [
                'workspace_id' => $workspace->id,
                'issue_id' => $theirIssue->id,
                'title' => 'Hacked',
            ])
            ->assertHasErrors(['not found']);

        $this->assertDatabaseHas('issues', ['id' => $theirIssue->id, 'title' => 'Theirs']);
    }

    public function test_membership_removal_is_enforced_on_next_call(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        TaskrServer::actingAs($member)
            ->tool(ListIssues::class, ['workspace_id' => $workspace->id])
            ->assertOk();

        $workspace->memberships()->where('user_id', $member->id)->delete();

        TaskrServer::actingAs($member)
            ->tool(ListIssues::class, ['workspace_id' => $workspace->id])
            ->assertHasErrors(['You are not a member of this workspace.']);
    }

    public function test_set_issue_parent_rejects_cycle_with_business_message(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        app(WorkspaceContext::class)->set($workspace);
        $parent = CreateIssueAction::run($workspace, ['project_id' => $project->id, 'title' => 'Parent']);
        $child = CreateIssueAction::run($workspace, ['project_id' => $project->id, 'title' => 'Child']);

        // Child under parent...
        TaskrServer::actingAs($owner)->tool(SetIssueParent::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $child->id,
            'parent_id' => $parent->id,
        ])->assertOk();

        // ...then parent under child must fail.
        TaskrServer::actingAs($owner)
            ->tool(SetIssueParent::class, [
                'workspace_id' => $workspace->id,
                'issue_id' => $parent->id,
                'parent_id' => $child->id,
            ])
            ->assertHasErrors(['cycle']);

        $this->assertDatabaseHas('issues', ['id' => $parent->id, 'parent_id' => null]);
    }

    public function test_add_comment_attributes_authenticated_user_as_author(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        app(WorkspaceContext::class)->set($workspace);
        $issue = CreateIssueAction::run($workspace, ['project_id' => $project->id, 'title' => 'Target']);

        $result = TaskrServer::actingAs($owner)->tool(AddComment::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $issue->id,
            'body' => 'From agent',
        ])->assertOk()->payload();

        $this->assertSame($owner->id, $result['comment']['authorId']);
        $this->assertDatabaseHas('comments', ['issue_id' => $issue->id, 'author_id' => $owner->id, 'body' => 'From agent']);
    }

    public function test_list_statuses_exposes_semantic_categories(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $result = TaskrServer::actingAs($owner)
            ->tool(ListStatuses::class, ['workspace_id' => $workspace->id])
            ->assertOk()
            ->payload();

        $categories = array_column($result['statuses'], 'category');
        foreach (['backlog', 'in_progress', 'done', 'cancelled'] as $expected) {
            $this->assertContains($expected, $categories);
        }
    }

    public function test_log_time_and_list_time_entries(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        app(WorkspaceContext::class)->set($workspace);
        $issue = CreateIssueAction::run($workspace, ['project_id' => $project->id, 'title' => 'Timed']);

        TaskrServer::actingAs($owner)->tool(LogTime::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $issue->id,
            'hours' => 1.5,
            'note' => 'Agent work',
        ])->assertOk();

        $result = TaskrServer::actingAs($owner)->tool(ListTimeEntries::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $issue->id,
        ])->assertOk()->payload();

        $this->assertCount(1, $result['timeEntries']);
        $this->assertSame(1.5, $result['timeEntries'][0]['hours']);
        $this->assertSame('Agent work', $result['timeEntries'][0]['note']);
    }

    public function test_projects_crud_via_mcp(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $created = TaskrServer::actingAs($owner)->tool(CreateProjectTool::class, [
            'workspace_id' => $workspace->id,
            'name' => 'Agent project',
        ])->assertOk()->payload()['project'];

        $this->assertMatchesRegularExpression('/^[0-9a-hjkmnp-tv-z]{26}$/i', $created['id']);

        $updated = TaskrServer::actingAs($owner)->tool(UpdateProjectTool::class, [
            'workspace_id' => $workspace->id,
            'project_id' => $created['id'],
            'name' => 'Renamed',
        ])->assertOk()->payload()['project'];
        $this->assertSame('Renamed', $updated['name']);

        $archived = TaskrServer::actingAs($owner)->tool(ArchiveProject::class, [
            'workspace_id' => $workspace->id,
            'project_id' => $created['id'],
        ])->assertOk()->payload()['project'];
        $this->assertNotNull($archived['archivedAt']);

        $listed = TaskrServer::actingAs($owner)->tool(ListProjects::class, [
            'workspace_id' => $workspace->id,
        ])->assertOk()->payload()['projects'];
        $this->assertContains('Renamed', array_column($listed, 'name'));
    }

    public function test_resource_read_returns_project_issues(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        app(WorkspaceContext::class)->set($workspace);
        CreateIssueAction::run($workspace, ['project_id' => $project->id, 'title' => 'Resourced']);

        $result = TaskrServer::actingAs($owner)
            ->resource(ProjectIssues::class, ['workspace_id' => $workspace->id, 'project_id' => $project->id])
            ->assertOk()
            ->result();

        /** @var array{issues: array<int, array{title: string}>} */
        $payload = json_decode($result['contents'][0]['text'], true);

        $this->assertSame(['Resourced'], array_column($payload['issues'], 'title'));
    }

    public function test_resource_read_refused_for_non_member(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $intruder = User::factory()->create();
        CreateWorkspace::run($intruder, ['name' => 'Theirs']);

        TaskrServer::actingAs($intruder)
            ->resource(ProjectIssues::class, ['workspace_id' => $workspace->id, 'project_id' => $project->id])
            ->assertHasErrors(['You are not a member of this workspace.']);
    }

    public function test_workspace_context_is_flushed_after_each_tool_call(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        TaskrServer::actingAs($owner)->tool(ListIssues::class, ['workspace_id' => $workspace->id])->assertOk();

        $this->assertNull(app(WorkspaceContext::class)->get());
    }

    public function test_authorization_error_is_distinct_from_validation_error(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $intruder = User::factory()->create();
        CreateWorkspace::run($intruder, ['name' => 'Theirs']);

        $authorization = TaskrServer::actingAs($intruder)
            ->tool(ListIssues::class, ['workspace_id' => $workspace->id])
            ->assertHasErrors()
            ->payload();

        $validation = TaskrServer::actingAs($owner)
            ->tool(CreateIssue::class, ['workspace_id' => $workspace->id, 'project_id' => $project->id, 'title' => ''])
            ->assertHasErrors()
            ->payload();

        $this->assertSame('forbidden', $authorization['error']);
        $this->assertSame('validation_failed', $validation['error']);
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

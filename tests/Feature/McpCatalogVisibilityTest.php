<?php

namespace Tests\Feature;

use App\Actions\Auth\CreateApiToken;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Mcp\Servers\TaskrServer;
use App\Mcp\Tools\CompareKnowledgeVersions;
use App\Mcp\Tools\CreateIssue;
use App\Mcp\Tools\CreateLabel;
use App\Mcp\Tools\DeleteKnowledge;
use App\Mcp\Tools\GetIssue;
use App\Mcp\Tools\GetKnowledge;
use App\Mcp\Tools\GetProjectHealth;
use App\Mcp\Tools\ListActivity;
use App\Mcp\Tools\ListComments;
use App\Mcp\Tools\ListIssues;
use App\Mcp\Tools\ListKnowledge;
use App\Mcp\Tools\ListKnowledgeTrash;
use App\Mcp\Tools\ListKnowledgeVersions;
use App\Mcp\Tools\ListLabels;
use App\Mcp\Tools\ListMembers;
use App\Mcp\Tools\ListProjects;
use App\Mcp\Tools\ListSavedViewIssues;
use App\Mcp\Tools\ListSavedViews;
use App\Mcp\Tools\ListStatuses;
use App\Mcp\Tools\ListTemplates;
use App\Mcp\Tools\ListTimeEntries;
use App\Mcp\Tools\ListWorkspaces;
use App\Mcp\Tools\LogTime;
use App\Mcp\Tools\SearchKnowledge;
use App\Mcp\Tools\UpdateIssue;
use App\Models\Membership;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestListResponse;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

class McpCatalogVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Tools reserved to full members (owner + member): guests never
     * discover them in tools/list.
     *
     * @var list<string>
     */
    private const FULL_MEMBER_TOOLS = [
        'create_project',
        'create_label',
        'list_members',
        'create_status',
        'update_status',
        'delete_status',
        'reorder_statuses',
        'create_template',
        'update_template',
        'delete_template',
    ];

    /**
     * Tools reserved to the workspace owner.
     *
     * @var list<string>
     */
    private const OWNER_TOOLS = ['invite_member', 'remove_member'];

    /**
     * Read-only tools: safe for agents to call without human approval,
     * advertised with the MCP readOnlyHint annotation.
     *
     * @var list<class-string>
     */
    private const READ_ONLY_TOOLS = [
        ListWorkspaces::class,
        ListMembers::class,
        ListProjects::class,
        GetProjectHealth::class,
        ListIssues::class,
        GetIssue::class,
        ListComments::class,
        ListStatuses::class,
        ListLabels::class,
        ListTimeEntries::class,
        ListKnowledge::class,
        GetKnowledge::class,
        SearchKnowledge::class,
        ListKnowledgeTrash::class,
        ListKnowledgeVersions::class,
        CompareKnowledgeVersions::class,
        ListTemplates::class,
        ListSavedViews::class,
        ListSavedViewIssues::class,
        ListActivity::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        TestResponse::macro('jsonRpcError', function (): ?array {
            /** @var TestResponse $this */
            return $this->response->toArray()['error'] ?? null;
        });

        TestListResponse::macro('namesFor', function (): array {
            /** @var TestListResponse $this */
            return collect($this->items)->pluck('name')->all();
        });
    }

    public function test_guest_catalog_hides_restricted_tools_and_keeps_perimeter_reads(): void
    {
        $token = $this->scopedGuestToken();

        $names = $this->toolsListNames($token);

        foreach ([...self::FULL_MEMBER_TOOLS, ...self::OWNER_TOOLS] as $hidden) {
            $this->assertNotContains($hidden, $names, "Guest catalog must hide [{$hidden}].");
        }

        foreach ([
            'list_workspaces',
            'list_projects',
            'get_project_health',
            'list_issues',
            'get_issue',
            'add_comment',
            'log_time',
            'list_time_entries',
            'search_knowledge',
            'get_knowledge',
            'list_activity',
            'move_issue',
            'set_issue_dependencies',
            'list_templates',
            'list_statuses',
            'list_labels',
            'list_saved_views',
        ] as $visible) {
            $this->assertContains($visible, $names, "Guest catalog must keep [{$visible}].");
        }
    }

    public function test_member_catalog_hides_only_owner_tools(): void
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);

        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        $names = $this->toolsListNames($this->tokenFor($member));

        foreach (self::OWNER_TOOLS as $hidden) {
            $this->assertNotContains($hidden, $names, "Member catalog must hide [{$hidden}].");
        }

        foreach (self::FULL_MEMBER_TOOLS as $visible) {
            $this->assertContains($visible, $names, "Member catalog must keep [{$visible}].");
        }
    }

    public function test_owner_catalog_exposes_every_tool(): void
    {
        $owner = User::factory()->create();
        CreateWorkspace::run($owner, ['name' => 'WS']);

        $names = $this->toolsListNames($this->tokenFor($owner));

        $this->assertSame(
            TaskrServer::tools()->namesFor(),
            $names,
            'An owner must discover the whole catalog.',
        );
    }

    public function test_static_catalog_without_user_stays_complete(): void
    {
        // Static introspection (docs, `mcp:list`, harnesses without a
        // session) must keep seeing the whole catalog: call-time
        // authorization remains the real guard.
        $names = TaskrServer::tools()->namesFor();

        $this->assertCount(57, $names);
        $this->assertContains('invite_member', $names);
        $this->assertContains('remove_member', $names);
        $this->assertContains('create_label', $names);
        $this->assertContains('get_issue', $names);
    }

    public function test_calling_a_hidden_tool_fails_as_unknown_tool_without_side_effects(): void
    {
        [$guest, $workspace] = $this->scopedGuest();

        $response = TaskrServer::actingAs($guest)->tool(CreateLabel::class, [
            'workspace_id' => $workspace->id,
            'name' => 'Espion',
        ]);

        $error = $response->jsonRpcError();

        $this->assertNotNull($error, 'A hidden tool must be refused as an unknown tool.');
        $this->assertSame(-32602, $error['code']);
        $this->assertStringContainsStringIgnoringCase('not found', $error['message']);
        $this->assertDatabaseMissing('labels', ['name' => 'Espion']);
    }

    public function test_read_only_tools_advertise_read_only_hint_without_title_duplication(): void
    {
        $readOnlyNames = [];

        foreach (self::READ_ONLY_TOOLS as $tool) {
            $array = (new $tool)->toArray();

            $this->assertSame(true, $array['annotations']['readOnlyHint'] ?? null, "[{$tool}] must advertise readOnlyHint.");
            $this->assertArrayNotHasKey('title', $array['annotations'], "[{$tool}] must not duplicate its title into annotations.");
            $readOnlyNames[] = $array['name'];
        }

        $this->assertCount(20, $readOnlyNames);

        // Mutating tools carry no readOnlyHint; a read-only tool with no
        // other annotation drops the annotations object entirely.
        $this->assertArrayNotHasKey('readOnlyHint', (new CreateIssue)->toArray());
        $this->assertArrayNotHasKey('readOnlyHint', (new LogTime)->toArray());
        $this->assertArrayNotHasKey('readOnlyHint', (new DeleteKnowledge)->toArray());
        $this->assertArrayNotHasKey('readOnlyHint', (new UpdateIssue)->toArray());
        $this->assertSame(['readOnlyHint' => true], (new ListIssues)->toArray()['annotations']);
        $this->assertSame(['readOnlyHint' => true], (new GetIssue)->toArray()['annotations']);
        $this->assertArrayNotHasKey('annotations', (new CreateIssue)->toArray());
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function scopedGuest(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $project = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);

        $guest = User::factory()->create();
        Membership::create([
            'user_id' => $guest->id,
            'workspace_id' => $workspace->id,
            'role' => 'guest',
        ]);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $guest->id,
            'role' => 'member',
        ]);

        return [$guest, $workspace];
    }

    /**
     * @return string bearer token of a scoped guest
     */
    private function scopedGuestToken(): string
    {
        [$guest] = $this->scopedGuest();

        return $this->tokenFor($guest);
    }

    private function tokenFor(User $user): string
    {
        $newToken = CreateApiToken::run($user, ['name' => 'tests']);

        return explode('|', $newToken->plainTextToken, 2)[1];
    }

    /**
     * @return list<string>
     */
    private function toolsListNames(string $token): array
    {
        $result = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => [],
        ], ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->json('result');

        $this->assertArrayNotHasKey('nextCursor', $result);

        return collect($result['tools'])->pluck('name')->all();
    }
}

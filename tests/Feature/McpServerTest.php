<?php

namespace Tests\Feature;

use App\Actions\Auth\CreateApiToken;
use App\Actions\Issues\CreateIssue as CreateIssueAction;
use App\Actions\Workspaces\CreateWorkspace;
use App\Mcp\Servers\TaskrServer;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestListResponse;
use Tests\TestCase;

class McpServerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TestListResponse::macro('namesFor', function (): array {
            /** @var TestListResponse $this */
            return collect($this->items)->pluck('name')->all();
        });
    }

    public function test_initialize_succeeds_with_valid_token(): void
    {
        $token = $this->createBearerToken();

        $response = $this->postJson('/mcp', $this->initializeBody(), $this->headers($token))
            ->assertOk()
            ->assertJsonPath('jsonrpc', '2.0')
            ->assertJsonPath('id', 1);

        $result = $response->json('result');

        $this->assertSame('2025-11-25', $result['protocolVersion']);
        $this->assertArrayHasKey('tools', $result['capabilities']);
        $this->assertArrayHasKey('resources', $result['capabilities']);
        $this->assertArrayHasKey('serverInfo', $result);
    }

    public function test_call_without_token_is_refused_and_returns_no_data(): void
    {
        $this->postJson('/mcp', $this->initializeBody())
            ->assertUnauthorized();
    }

    public function test_call_with_malformed_token_is_refused(): void
    {
        $this->postJson('/mcp', $this->initializeBody(), $this->headers('not-a-real-token'))
            ->assertUnauthorized();
    }

    public function test_non_mcp_request_is_rejected_with_protocol_error(): void
    {
        $token = $this->createBearerToken();

        $response = $this->call('POST', '/mcp', [], [], [], $this->serverVars($token), 'not-json');
        $response->assertStatus(400);

        $this->assertSame(-32700, $response->json('error.code'));
    }

    public function test_get_on_mcp_endpoint_is_rejected(): void
    {
        $token = $this->createBearerToken();

        $this->get('/mcp', $this->headers($token))
            ->assertStatus(405);
    }

    public function test_tools_list_first_page_exposes_the_whole_catalog(): void
    {
        $token = $this->createBearerToken();

        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => [],
        ], $this->headers($token))->assertOk();

        $result = $response->json('result');

        // A single page must carry every tool: agents that read only the
        // first page (the common case) must discover the time-entry tools.
        $this->assertArrayNotHasKey('nextCursor', $result);

        $names = collect($result['tools'])->pluck('name')->all();

        $this->assertSame(
            TaskrServer::tools()->namesFor(),
            $names,
            'The first tools/list page must expose the whole catalog in one response.',
        );
        $this->assertContains('log_time', $names);
        $this->assertContains('list_time_entries', $names);
        $this->assertContains('update_time_entry', $names);
        $this->assertContains('delete_time_entry', $names);
    }

    public function test_tools_list_honors_a_large_per_page_request(): void
    {
        $token = $this->createBearerToken();

        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => ['per_page' => 1000],
        ], $this->headers($token))->assertOk();

        $result = $response->json('result');

        $this->assertArrayNotHasKey('nextCursor', $result);
        $this->assertSame(
            TaskrServer::tools()->namesFor(),
            collect($result['tools'])->pluck('name')->all(),
        );
    }

    public function test_agent_can_update_and_delete_a_time_entry_over_http(): void
    {
        [$token, $workspaceId, $issueId] = $this->createAgentContext();

        $logged = $this->callTool($token, 'log_time', [
            'workspace_id' => $workspaceId,
            'issue_id' => $issueId,
            'hours' => 1.5,
            'note' => 'initial',
        ], 2);

        $entryId = $logged['result']['structuredContent']['timeEntry']['id'];

        $updated = $this->callTool($token, 'update_time_entry', [
            'workspace_id' => $workspaceId,
            'issue_id' => $issueId,
            'time_entry_id' => $entryId,
            'hours' => 3.25,
            'note' => 'corrected',
        ], 3);

        $this->assertFalse($updated['result']['isError']);
        $this->assertSame(3.25, $updated['result']['structuredContent']['timeEntry']['hours']);
        $this->assertSame('corrected', $updated['result']['structuredContent']['timeEntry']['note']);

        $deleted = $this->callTool($token, 'delete_time_entry', [
            'workspace_id' => $workspaceId,
            'issue_id' => $issueId,
            'time_entry_id' => $entryId,
        ], 4);

        $this->assertFalse($deleted['result']['isError']);
        $this->assertTrue($deleted['result']['structuredContent']['deleted']);
    }

    /**
     * @return array{0: string, 1: string, 2: string} token, workspace id, issue id
     */
    private function createAgentContext(): array
    {
        $user = User::factory()->create();
        $workspace = CreateWorkspace::run($user, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $project = $workspace->projects()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Project',
        ]);

        $issue = CreateIssueAction::run($workspace, [
            'project_id' => $project->id,
            'title' => 'Timed',
        ]);

        $newToken = CreateApiToken::run($user, ['name' => 'tests']);

        return [explode('|', $newToken->plainTextToken, 2)[1], $workspace->id, $issue->id];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function callTool(string $token, string $name, array $arguments, int $id): array
    {
        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ], $this->headers($token))->assertOk();

        return $response->json();
    }

    private function createBearerToken(): string
    {
        $user = User::factory()->create();
        CreateWorkspace::run($user, ['name' => 'WS']);

        $newToken = CreateApiToken::run($user, ['name' => 'tests']);

        return explode('|', $newToken->plainTextToken, 2)[1];
    }

    /**
     * @return array<string, mixed>
     */
    private function initializeBody(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => new \stdClass,
                'clientInfo' => ['name' => 'tests', 'version' => '1.0'],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    /**
     * @return array<string, string>
     */
    private function serverVars(string $token): array
    {
        return [
            'HTTP_AUTHORIZATION' => "Bearer {$token}",
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];
    }
}

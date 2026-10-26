<?php

namespace Tests\Feature;

use App\Mcp\Servers\TaskrServer;
use App\Mcp\Tools\CreateLabel;
use App\Mcp\Tools\CreateWorkspace;
use App\Mcp\Tools\ListLabels;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

/**
 * Socle agents (5/5) — the optional workspace template: creating a
 * workspace through the MCP tool can seed the agent factory's two
 * qualification labels. Off by default: no workspace is ever imposed
 * the convention, and the web journey stays label-free.
 */
class McpAgentWorkspaceTemplateTest extends TestCase
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

    public function test_mcp_create_workspace_with_agent_labels_seeds_the_convention(): void
    {
        $agent = User::factory()->create();

        $result = TaskrServer::actingAs($agent)->tool(CreateWorkspace::class, [
            'name' => 'Usine orchestrée',
            'with_agent_labels' => true,
        ])->assertOk()->payload();

        $workspaceId = $result['workspace']['id'];

        $labels = TaskrServer::actingAs($agent)
            ->tool(ListLabels::class, ['workspace_id' => $workspaceId])
            ->assertOk()
            ->payload()['labels'];

        $byName = collect($labels)->keyBy('name');

        $this->assertSame('green', $byName['agent-ready']['color']);
        $this->assertSame('red', $byName['agent-failed']['color']);

        $this->assertDatabaseHas('labels', ['workspace_id' => $workspaceId, 'name' => 'agent-ready', 'color' => 'green']);
        $this->assertDatabaseHas('labels', ['workspace_id' => $workspaceId, 'name' => 'agent-failed', 'color' => 'red']);
    }

    public function test_mcp_create_workspace_stays_label_free_by_default(): void
    {
        $agent = User::factory()->create();

        $result = TaskrServer::actingAs($agent)->tool(CreateWorkspace::class, [
            'name' => 'Workspace sobre',
        ])->assertOk()->payload();

        $this->assertDatabaseCount('labels', 0);

        $labels = TaskrServer::actingAs($agent)
            ->tool(ListLabels::class, ['workspace_id' => $result['workspace']['id']])
            ->assertOk()
            ->payload()['labels'];

        $this->assertSame([], $labels);
    }

    public function test_web_registration_creates_no_labels(): void
    {
        $this->post('/register', [
            'name' => 'Hadrien',
            'email' => 'hadrien@taskr.dev',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect();

        $user = User::where('email', 'hadrien@taskr.dev')->firstOrFail();
        $workspace = $user->memberships()->first()->workspace;

        $this->assertDatabaseCount('labels', 0);
        $this->assertSame(0, $workspace->labels()->count(), 'the web journey never imposes the agent convention');
    }

    public function test_seeded_qualification_labels_are_standard_labels(): void
    {
        $agent = User::factory()->create();

        $result = TaskrServer::actingAs($agent)->tool(CreateWorkspace::class, [
            'name' => 'Usine orchestrée',
            'with_agent_labels' => true,
        ])->assertOk()->payload();

        TaskrServer::actingAs($agent)->tool(CreateLabel::class, [
            'workspace_id' => $result['workspace']['id'],
            'name' => 'agent-ready',
            'color' => 'green',
        ])->assertHasErrors();

        $this->assertDatabaseCount('labels', 2);
    }
}

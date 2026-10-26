<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Workspaces\CreateWorkspace;
use App\Mcp\Servers\TaskrServer;
use App\Mcp\Tools\CreateLabel;
use App\Mcp\Tools\ListIssues;
use App\Mcp\Tools\ListLabels;
use App\Mcp\Tools\SetLabels;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

/**
 * Socle agents (3/5) — the agent factory's qualification loop, locked by
 * tests: two conventional labels (agent-ready / agent-failed, created by
 * the bot itself, never seeded), qualification through set_labels, and
 * the label-filtered reads the factory's gate relies on.
 */
class McpAgentQualificationTest extends TestCase
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

    /**
     * @return array{0: User, 1: Workspace, 2: Project}
     */
    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'Usine']);

        app(WorkspaceContext::class)->set($workspace);

        $project = $workspace->projects()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Conveyor',
        ]);

        return [$owner, $workspace, $project];
    }

    /**
     * The conventional qualification labels as the factory bot creates
     * them: agent-ready opens the specification gate, agent-failed
     * traces a factory failure.
     *
     * @return array{0: string, 1: string} Label ULIDs [ready, failed]
     */
    private function createQualificationLabels(User $agent, $workspace): array
    {
        $ready = TaskrServer::actingAs($agent)->tool(CreateLabel::class, [
            'workspace_id' => $workspace->id,
            'name' => 'agent-ready',
            'color' => 'green',
        ])->assertOk()->payload()['label'];

        $failed = TaskrServer::actingAs($agent)->tool(CreateLabel::class, [
            'workspace_id' => $workspace->id,
            'name' => 'agent-failed',
            'color' => 'red',
        ])->assertOk()->payload()['label'];

        return [$ready['id'], $failed['id']];
    }

    public function test_agent_creates_the_two_qualification_labels(): void
    {
        [$agent, $workspace] = $this->setupWorkspace();

        [$readyId, $failedId] = $this->createQualificationLabels($agent, $workspace);

        $labels = TaskrServer::actingAs($agent)
            ->tool(ListLabels::class, ['workspace_id' => $workspace->id])
            ->assertOk()
            ->payload()['labels'];

        $byName = collect($labels)->keyBy('name');

        $this->assertSame('green', $byName['agent-ready']['color']);
        $this->assertSame('red', $byName['agent-failed']['color']);
        $this->assertSame($readyId, $byName['agent-ready']['id']);
        $this->assertSame($failedId, $byName['agent-failed']['id']);
    }

    public function test_agent_qualifies_an_issue_and_reads_it_back(): void
    {
        [$agent, $workspace, $project] = $this->setupWorkspace();
        $issue = CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'Spec gate candidate',
        ]);
        [$readyId] = $this->createQualificationLabels($agent, $workspace);

        TaskrServer::actingAs($agent)->tool(SetLabels::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $issue->id,
            'label_ids' => [$readyId],
        ])->assertOk();

        $listed = TaskrServer::actingAs($agent)
            ->tool(ListIssues::class, ['workspace_id' => $workspace->id])
            ->assertOk()
            ->payload()['issues'];

        $entry = collect($listed)->firstWhere('id', $issue->id);
        $this->assertNotNull($entry, 'the qualified issue stays visible in the plain listing');
        $this->assertSame('agent-ready', $entry['labels'][0]['name']);

        // The gate read: label-filtered listing isolates the qualified ticket.
        $gated = TaskrServer::actingAs($agent)
            ->tool(ListIssues::class, ['workspace_id' => $workspace->id, 'label' => $readyId])
            ->assertOk()
            ->payload();

        $this->assertSame([$issue->id], collect($gated['issues'])->pluck('id')->all());
        $this->assertSame(1, $gated['total']);
    }

    public function test_agent_traces_a_failure_by_swapping_qualification(): void
    {
        [$agent, $workspace, $project] = $this->setupWorkspace();
        $issue = CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'Will fail the factory',
        ]);
        [$readyId, $failedId] = $this->createQualificationLabels($agent, $workspace);

        TaskrServer::actingAs($agent)->tool(SetLabels::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $issue->id,
            'label_ids' => [$readyId],
        ])->assertOk();

        // The factory failed: agent-failed replaces agent-ready — the ticket
        // leaves the gate and the failure stays traceable.
        TaskrServer::actingAs($agent)->tool(SetLabels::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $issue->id,
            'label_ids' => [$failedId],
        ])->assertOk();

        $gate = TaskrServer::actingAs($agent)
            ->tool(ListIssues::class, ['workspace_id' => $workspace->id, 'label' => $readyId])
            ->assertOk()
            ->payload();

        $this->assertSame(0, $gate['total']);

        $failures = TaskrServer::actingAs($agent)
            ->tool(ListIssues::class, ['workspace_id' => $workspace->id, 'label' => $failedId])
            ->assertOk()
            ->payload();

        $this->assertSame([$issue->id], collect($failures['issues'])->pluck('id')->all());
    }

    public function test_guest_cannot_create_qualification_labels(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $guest = User::factory()->create();
        Membership::create([
            'user_id' => $guest->id,
            'workspace_id' => $workspace->id,
            'role' => 'guest',
        ]);

        TaskrServer::actingAs($guest)->tool(CreateLabel::class, [
            'workspace_id' => $workspace->id,
            'name' => 'agent-ready',
            'color' => 'green',
        ])->assertHasErrors(['Only full members can manage labels.']);

        $this->assertDatabaseMissing('labels', [
            'workspace_id' => $workspace->id,
            'name' => 'agent-ready',
        ]);
    }
}

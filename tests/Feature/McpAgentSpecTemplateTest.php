<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspace;
use App\Mcp\Servers\TaskrServer;
use App\Mcp\Tools\CreateTemplate;
use App\Mcp\Tools\ListTemplates;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

/**
 * Socle agents (4/5) — the official « agent » issue template, the
 * specification gate's upstream tooling: a workspace template carrying
 * the five-section structure a ticket must follow before it can be
 * qualified agent-ready. Created through the existing create_template
 * tool (owner-only), never seeded.
 */
class McpAgentSpecTemplateTest extends TestCase
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
     * The official template body: the five sections every agent-ready
     * candidate must fill.
     */
    private function officialBody(): string
    {
        return <<<'MD'
## Contexte

Pourquoi ce ticket existe : le besoin, le lien au parent ou à l'épique.

## Comportement attendu

Ce que le système doit faire, décrit de façon observable et sans ambiguïté.

## Critères d'acceptation

- test_... : chaque critère est testable et sera verrouillé par un test nommé.

## Indices techniques

Fichiers, actions, pages, outils MCP ou pages knowledge concernés — ce qu'un agent doit lire en premier.

## Hors périmètre

Ce que ce ticket ne fait pas explicitement, pour borner l'usine.
MD;
    }

    public function test_owner_creates_the_official_agent_template(): void
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'Usine']);

        $result = TaskrServer::actingAs($owner)->tool(CreateTemplate::class, [
            'workspace_id' => $workspace->id,
            'name' => 'Agent',
            'description' => $this->officialBody(),
        ])->assertOk()->payload();

        $this->assertSame('Agent', $result['template']['name']);

        foreach (['## Contexte', '## Comportement attendu', '## Critères d\'acceptation', '## Indices techniques', '## Hors périmètre'] as $section) {
            $this->assertStringContainsString($section, $result['template']['description']);
        }

        $listed = TaskrServer::actingAs($owner)
            ->tool(ListTemplates::class, ['workspace_id' => $workspace->id])
            ->assertOk()
            ->payload()['templates'];

        $entry = collect($listed)->firstWhere('name', 'Agent');
        $this->assertNotNull($entry, 'the official template is listed for the creation dialogs');
        $this->assertStringContainsString('## Critères d\'acceptation', $entry['description']);
    }

    public function test_member_cannot_create_templates(): void
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'Usine']);

        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        TaskrServer::actingAs($member)->tool(CreateTemplate::class, [
            'workspace_id' => $workspace->id,
            'name' => 'Agent',
            'description' => $this->officialBody(),
        ])->assertHasErrors(['Only the workspace owner may create templates.']);

        $this->assertDatabaseMissing('issue_templates', [
            'workspace_id' => $workspace->id,
            'name' => 'Agent',
        ]);
    }

    public function test_agent_template_name_is_unique_per_workspace(): void
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'Usine']);

        TaskrServer::actingAs($owner)->tool(CreateTemplate::class, [
            'workspace_id' => $workspace->id,
            'name' => 'Agent',
            'description' => $this->officialBody(),
        ])->assertOk();

        TaskrServer::actingAs($owner)->tool(CreateTemplate::class, [
            'workspace_id' => $workspace->id,
            'name' => 'Agent',
            'description' => $this->officialBody(),
        ])->assertHasErrors();

        $this->assertDatabaseCount('issue_templates', 1);
    }
}

<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Mcp\Servers\TaskrServer;
use App\Mcp\Tools\CreateIssue as CreateIssueTool;
use App\Mcp\Tools\SetAssignees as SetAssigneesTool;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

/**
 * Assignable = a roster line on the issue's project, for every actor
 * and every channel: the workspace owner included, web UI and agents
 * alike. Anything else must be refused by validation.
 */
class IssueAssigneeRosterTest extends TestCase
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
     * Alice owns the workspace and the project (roster owner line),
     * Bob is a project teammate, Zoe is a workspace member with no
     * line on the project — the out-of-roster target.
     *
     * @return array{0: User, 1: Workspace, 2: Project, 3: Issue, 4: User, 5: User}
     */
    private function setupRoster(): array
    {
        $owner = User::factory()->create(['name' => 'Alice']);
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);
        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Ticket equipe']);

        $teammate = User::factory()->create(['name' => 'Bob']);
        Membership::create(['user_id' => $teammate->id, 'workspace_id' => $workspace->id, 'role' => 'member']);
        ProjectMember::create(['project_id' => $project->id, 'user_id' => $teammate->id, 'role' => 'member']);

        $outsider = User::factory()->create(['name' => 'Zoe']);
        Membership::create(['user_id' => $outsider->id, 'workspace_id' => $workspace->id, 'role' => 'member']);

        return [$owner, $workspace, $project, $issue, $teammate, $outsider];
    }

    // ── Attribution sur une issue existante (web) ─────────────────────

    public function test_owner_cannot_assign_an_out_of_roster_workspace_member(): void
    {
        [$owner, $workspace, $project, $issue, $teammate, $outsider] = $this->setupRoster();

        $this->actingAs($owner)
            ->post(route('issues.assignees', $issue->id), ['assignees' => [$outsider->id]])
            ->assertSessionHasErrors('assignees.0');

        $this->assertDatabaseMissing('issue_assignees', [
            'issue_id' => $issue->id,
            'user_id' => $outsider->id,
        ]);
    }

    public function test_owner_can_assign_a_project_roster_member(): void
    {
        [$owner, $workspace, $project, $issue, $teammate, $outsider] = $this->setupRoster();

        $this->actingAs($owner)
            ->post(route('issues.assignees', $issue->id), ['assignees' => [$teammate->id]])
            ->assertRedirect();

        $this->assertDatabaseHas('issue_assignees', [
            'issue_id' => $issue->id,
            'user_id' => $teammate->id,
        ]);
    }

    // ── Création d'issue (web) ────────────────────────────────────────

    public function test_owner_cannot_create_an_issue_with_an_out_of_roster_assignee(): void
    {
        [$owner, $workspace, $project, $issue, $teammate, $outsider] = $this->setupRoster();

        $this->actingAs($owner)
            ->post('/issues', [
                'project_id' => $project->id,
                'title' => 'Avec un hors equipe',
                'assignees' => [$outsider->id],
            ])
            ->assertSessionHasErrors('assignees.0');

        $this->assertDatabaseMissing('issues', ['title' => 'Avec un hors equipe']);
    }

    // ── Agents (MCP) : même règle, contexte sans requête web ─────────

    public function test_mcp_set_assignees_rejects_an_out_of_roster_member_for_a_roster_driven_actor(): void
    {
        [$owner, $workspace, $project, $issue, $teammate, $outsider] = $this->setupRoster();

        $result = TaskrServer::actingAs($teammate)
            ->tool(SetAssigneesTool::class, [
                'workspace_id' => $workspace->id,
                'issue_id' => $issue->id,
                'assignees' => [$outsider->id],
            ])
            ->assertHasErrors()
            ->payload();

        $this->assertSame('validation_failed', $result['error']);
        $this->assertArrayHasKey('assignees.0', $result['fields']);

        $this->assertDatabaseMissing('issue_assignees', [
            'issue_id' => $issue->id,
            'user_id' => $outsider->id,
        ]);
    }

    public function test_mcp_set_assignees_accepts_roster_members_for_a_roster_driven_actor(): void
    {
        [$owner, $workspace, $project, $issue, $teammate, $outsider] = $this->setupRoster();

        TaskrServer::actingAs($teammate)
            ->tool(SetAssigneesTool::class, [
                'workspace_id' => $workspace->id,
                'issue_id' => $issue->id,
                'assignees' => [$owner->id],
            ])
            ->assertOk();

        $this->assertDatabaseHas('issue_assignees', [
            'issue_id' => $issue->id,
            'user_id' => $owner->id,
        ]);
    }

    public function test_mcp_create_issue_rejects_an_out_of_roster_assignee(): void
    {
        [$owner, $workspace, $project, $issue, $teammate, $outsider] = $this->setupRoster();

        $result = TaskrServer::actingAs($owner)
            ->tool(CreateIssueTool::class, [
                'workspace_id' => $workspace->id,
                'project_id' => $project->id,
                'title' => 'Ticket agent hors equipe',
                'assignees' => [$outsider->id],
            ])
            ->assertHasErrors()
            ->payload();

        $this->assertSame('validation_failed', $result['error']);
        $this->assertArrayHasKey('assignees.0', $result['fields']);

        $this->assertDatabaseMissing('issues', ['title' => 'Ticket agent hors equipe']);

        // The same creation with a roster member goes through.
        TaskrServer::actingAs($owner)
            ->tool(CreateIssueTool::class, [
                'workspace_id' => $workspace->id,
                'project_id' => $project->id,
                'title' => 'Ticket agent equipe',
                'assignees' => [$teammate->id],
            ])
            ->assertOk();

        $this->assertDatabaseHas('issues', ['title' => 'Ticket agent equipe']);
    }

    // ── Interface : ne proposer que l'équipe du projet ────────────────

    public function test_issue_page_offers_only_the_project_roster_as_candidates(): void
    {
        [$owner, $workspace, $project, $issue, $teammate, $outsider] = $this->setupRoster();

        $this->actingAs($owner)
            ->get(route('issues.show', $issue->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('members', [
                    ['id' => $owner->id, 'name' => 'Alice'],
                    ['id' => $teammate->id, 'name' => 'Bob'],
                ]));
    }

    public function test_project_board_offers_only_the_project_roster_as_candidates(): void
    {
        [$owner, $workspace, $project, $issue, $teammate, $outsider] = $this->setupRoster();

        $this->actingAs($owner)
            ->get(route('projects.board', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('members', [
                    ['id' => $owner->id, 'name' => 'Alice'],
                    ['id' => $teammate->id, 'name' => 'Bob'],
                ]));
    }
}

<?php

namespace Tests\Feature;

use App\Actions\Comments\AddComment;
use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\TimeTracking\CreateTimeEntry;
use App\Actions\Workspaces\CreateWorkspace;
use App\Mcp\Servers\TaskrServer;
use App\Mcp\Tools\ListComments;
use App\Mcp\Tools\ListIssues;
use App\Mcp\Tools\ListTimeEntries;
use App\Mcp\Tools\UpdateIssue as UpdateIssueTool;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

class IssueScopeTest extends TestCase
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
     * Guest scoped to one project, with issues on both sides.
     *
     * @return array{0: User, 1: Workspace, 2: Issue, 3: Issue, 4: User}
     */
    private function setupScopedGuest(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $inScope = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $outOfScope = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

        $inScopeIssue = CreateIssue::run($workspace, ['project_id' => $inScope->id, 'title' => 'Dans le perimetre']);
        $outOfScopeIssue = CreateIssue::run($workspace, ['project_id' => $outOfScope->id, 'title' => 'Hors perimetre']);

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

        return [$owner, $workspace, $inScopeIssue, $outOfScopeIssue, $guest];
    }

    // ── Vues transverses ──────────────────────────────────────────────

    public function test_guest_issues_page_lists_only_scope_issues(): void
    {
        [$owner, $workspace, $inScopeIssue, $outOfScopeIssue, $guest] = $this->setupScopedGuest();

        $this->actingAs($guest)
            ->get(route('issues.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 1)
                ->where('issues.0.id', $inScopeIssue->id)
                ->where('totalUnfiltered', 1));
    }

    public function test_search_ulid_respects_guest_scope(): void
    {
        [$owner, $workspace, $inScopeIssue, $outOfScopeIssue, $guest] = $this->setupScopedGuest();

        // The out-of-perimeter ticket id is pasted verbatim: a guest must not
        // see it surface through the id search.
        $this->actingAs($guest)
            ->get(route('issues.index', ['q' => $outOfScopeIssue->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 0)
                ->where('total', 0)
                ->where('totalUnfiltered', 1));

        // Same for a prefix shared by both tickets: only the in-scope one shows.
        $prefix = substr($inScopeIssue->id, 0, 7);
        $outOfScopeIssue->forceFill(['id' => $prefix.substr($outOfScopeIssue->id, 7)])->save();

        $this->actingAs($guest)
            ->get(route('issues.index', ['q' => $prefix]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 1)
                ->where('issues.0.id', $inScopeIssue->id));
    }

    public function test_guest_kanban_builds_only_scope_boards(): void
    {
        [$owner, $workspace, $inScopeIssue, $outOfScopeIssue, $guest] = $this->setupScopedGuest();

        $this->actingAs($guest)
            ->get(route('issues.index', ['view' => 'kanban']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('projectBoards', 1)
                ->where('projectBoards.0.project.name', 'Alpha'));
    }

    public function test_member_still_sees_every_issue(): void
    {
        [$owner, $workspace, $inScopeIssue, $outOfScopeIssue, $guest] = $this->setupScopedGuest();
        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        $this->actingAs($member)
            ->get(route('issues.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 2)
                ->where('totalUnfiltered', 2));
    }

    // ── Page de détail ───────────────────────────────────────────────

    public function test_guest_issue_detail_out_of_scope_is_not_found(): void
    {
        [$owner, $workspace, $inScopeIssue, $outOfScopeIssue, $guest] = $this->setupScopedGuest();

        $this->actingAs($guest)
            ->get(route('issues.show', $outOfScopeIssue->id))
            ->assertNotFound();
    }

    public function test_guest_parent_candidates_are_scoped_on_detail_page(): void
    {
        [$owner, $workspace, $inScopeIssue, $outOfScopeIssue, $guest] = $this->setupScopedGuest();
        $child = CreateIssue::run($workspace, ['project_id' => $inScopeIssue->project_id, 'title' => 'Enfant']);

        $this->actingAs($guest)
            ->get(route('issues.show', $child->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $props) => $props
                ->has('candidates', 1)
                ->where('candidates.0.id', $inScopeIssue->id));
    }

    // ── CRUD ─────────────────────────────────────────────────────────

    public function test_guest_cannot_create_an_issue_out_of_scope(): void
    {
        [$owner, $workspace, $inScopeIssue, $outOfScopeIssue, $guest] = $this->setupScopedGuest();

        $this->actingAs($guest)
            ->post(route('issues.store'), [
                'project_id' => $outOfScopeIssue->project_id,
                'title' => 'Fuite',
            ])
            ->assertSessionHasErrors('project_id');

        $this->assertDatabaseMissing('issues', [
            'title' => 'Fuite',
        ]);
    }

    public function test_guest_can_create_an_issue_in_scope(): void
    {
        [$owner, $workspace, $inScopeIssue, $outOfScopeIssue, $guest] = $this->setupScopedGuest();

        $this->actingAs($guest)
            ->post(route('issues.store'), [
                'project_id' => $inScopeIssue->project_id,
                'title' => 'Nouveau ticket client',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('issues', [
            'title' => 'Nouveau ticket client',
        ]);
    }

    public function test_guest_cannot_update_an_out_of_scope_issue(): void
    {
        [$owner, $workspace, $inScopeIssue, $outOfScopeIssue, $guest] = $this->setupScopedGuest();

        $this->actingAs($guest)
            ->patch(route('issues.update', $outOfScopeIssue->id), ['title' => 'Pirate'])
            ->assertNotFound();

        $this->assertDatabaseHas('issues', [
            'id' => $outOfScopeIssue->id,
            'title' => 'Hors perimetre',
        ]);
    }

    public function test_guest_cannot_delete_an_out_of_scope_issue(): void
    {
        [$owner, $workspace, $inScopeIssue, $outOfScopeIssue, $guest] = $this->setupScopedGuest();

        $this->actingAs($guest)
            ->delete(route('issues.destroy', $outOfScopeIssue->id))
            ->assertNotFound();

        $this->assertDatabaseHas('issues', ['id' => $outOfScopeIssue->id]);
    }

    public function test_guest_cannot_move_an_out_of_scope_issue(): void
    {
        [$owner, $workspace, $inScopeIssue, $outOfScopeIssue, $guest] = $this->setupScopedGuest();
        $status = $workspace->statuses()->first();

        $this->actingAs($guest)
            ->post(route('issues.move', $outOfScopeIssue->id), ['status_id' => $status->id])
            ->assertNotFound();
    }

    public function test_guest_cannot_set_labels_on_out_of_scope_issue(): void
    {
        [$owner, $workspace, $inScopeIssue, $outOfScopeIssue, $guest] = $this->setupScopedGuest();

        $this->actingAs($guest)
            ->post(route('issues.labels', $outOfScopeIssue->id), ['label_ids' => []])
            ->assertNotFound();
    }

    // ── Hiérarchie et assignation ────────────────────────────────────

    public function test_guest_cannot_set_an_out_of_scope_parent(): void
    {
        [$owner, $workspace, $inScopeIssue, $outOfScopeIssue, $guest] = $this->setupScopedGuest();

        $this->actingAs($guest)
            ->post(route('issues.parent', $inScopeIssue->id), ['parent_id' => $outOfScopeIssue->id])
            ->assertSessionHasErrors('parent_id');

        $this->assertDatabaseHas('issues', [
            'id' => $inScopeIssue->id,
            'parent_id' => null,
        ]);
    }

    public function test_guest_can_only_assign_roster_members_of_the_issue_project(): void
    {
        [$owner, $workspace, $inScopeIssue, $outOfScopeIssue, $guest] = $this->setupScopedGuest();

        // A workspace member who is NOT on the issue project's roster.
        $outsider = User::factory()->create();
        Membership::create([
            'user_id' => $outsider->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        $this->actingAs($guest)
            ->post(route('issues.assignees', $inScopeIssue->id), ['assignees' => [$outsider->id]])
            ->assertSessionHasErrors('assignees.0');

        $this->assertDatabaseMissing('issue_assignees', [
            'issue_id' => $inScopeIssue->id,
            'user_id' => $outsider->id,
        ]);
    }

    // ── Commentaires et temps ─────────────────────────────────────────

    public function test_guest_cannot_comment_on_out_of_scope_issue(): void
    {
        [$owner, $workspace, $inScopeIssue, $outOfScopeIssue, $guest] = $this->setupScopedGuest();

        $this->actingAs($guest)
            ->post(route('comments.store', $outOfScopeIssue->id), ['body' => 'Fuite'])
            ->assertNotFound();

        $this->assertDatabaseMissing('comments', [
            'issue_id' => $outOfScopeIssue->id,
            'body' => 'Fuite',
        ]);
    }

    public function test_guest_cannot_log_time_on_out_of_scope_issue(): void
    {
        [$owner, $workspace, $inScopeIssue, $outOfScopeIssue, $guest] = $this->setupScopedGuest();

        $this->actingAs($guest)
            ->post(route('issues.time-entries.store', $outOfScopeIssue->id), ['hours' => 1])
            ->assertNotFound();

        $this->assertDatabaseMissing('time_entries', [
            'issue_id' => $outOfScopeIssue->id,
        ]);
    }

    // ── MCP ──────────────────────────────────────────────────────────

    public function test_guest_mcp_list_issues_returns_only_scope_issues(): void
    {
        [$owner, $workspace, $inScopeIssue, $outOfScopeIssue, $guest] = $this->setupScopedGuest();

        $listed = TaskrServer::actingAs($guest)->tool(ListIssues::class, [
            'workspace_id' => $workspace->id,
        ])->assertOk()->payload()['issues'];

        $this->assertSame([$inScopeIssue->id], array_column($listed, 'id'));
    }

    public function test_guest_mcp_update_issue_out_of_scope_is_not_found(): void
    {
        [$owner, $workspace, $inScopeIssue, $outOfScopeIssue, $guest] = $this->setupScopedGuest();

        $response = TaskrServer::actingAs($guest)->tool(UpdateIssueTool::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $outOfScopeIssue->id,
            'title' => 'Pirate',
        ]);

        $this->assertSame('not_found', $response->payload()['error']);
    }

    // ── Objets hérités d'un périmètre rétréci ────────────────────────

    public function test_guest_cannot_edit_or_delete_their_comment_once_out_of_scope(): void
    {
        [$owner, $workspace, $inScopeIssue, $outOfScopeIssue, $guest] = $this->setupScopedGuest();

        // The guest comments while in scope, then the owner shrinks the perimeter.
        $comment = AddComment::run($inScopeIssue, $guest, 'Note de travail');
        $timeEntry = CreateTimeEntry::run($inScopeIssue, $guest, null, 1.5);
        ProjectMember::query()
            ->where('project_id', $inScopeIssue->project_id)
            ->where('user_id', $guest->id)
            ->delete();

        $this->actingAs($guest)
            ->patch(route('comments.update', [$inScopeIssue->id, $comment->id]), ['body' => 'Retouch'])
            ->assertNotFound();

        $this->actingAs($guest)
            ->delete(route('comments.destroy', [$inScopeIssue->id, $comment->id]))
            ->assertNotFound();

        $this->actingAs($guest)
            ->patch(route('issues.time-entries.update', [$inScopeIssue->id, $timeEntry->id]), ['hours' => 2])
            ->assertNotFound();

        $this->actingAs($guest)
            ->delete(route('issues.time-entries.destroy', [$inScopeIssue->id, $timeEntry->id]))
            ->assertNotFound();
    }

    public function test_guest_mcp_lists_comments_and_time_only_in_scope(): void
    {
        [$owner, $workspace, $inScopeIssue, $outOfScopeIssue, $guest] = $this->setupScopedGuest();

        $comments = TaskrServer::actingAs($guest)->tool(ListComments::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $outOfScopeIssue->id,
        ]);

        $this->assertSame('not_found', $comments->payload()['error']);

        $timeEntries = TaskrServer::actingAs($guest)->tool(ListTimeEntries::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $outOfScopeIssue->id,
        ]);

        $this->assertSame('not_found', $timeEntries->payload()['error']);
    }
}

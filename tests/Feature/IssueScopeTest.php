<?php

namespace Tests\Feature;

use App\Actions\Comments\AddComment;
use App\Actions\Issues\CreateIssue;
use App\Actions\Issues\SetAssignees;
use App\Actions\Projects\CreateProject;
use App\Actions\TimeTracking\CreateTimeEntry;
use App\Actions\Workspaces\CreateWorkspace;
use App\Mcp\Servers\TaskrServer;
use App\Mcp\Tools\CreateIssue as CreateIssueTool;
use App\Mcp\Tools\ListComments;
use App\Mcp\Tools\ListIssues;
use App\Mcp\Tools\ListTimeEntries;
use App\Mcp\Tools\UpdateIssue as UpdateIssueTool;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\ProjectMember;
use App\Models\Scopes\WorkspaceScope;
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
            ->get(route('issues.index', ['view' => 'kanban']), [
                'X-Inertia-Partial-Component' => 'Issues/Index',
                'X-Inertia-Partial-Data' => 'projectBoards',
            ])
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('projectBoards', 1)
                ->where('projectBoards.0.project.name', 'Alpha'));
    }

    public function test_teamed_member_sees_their_team_issues(): void
    {
        [$owner, $workspace, $inScopeIssue, $outOfScopeIssue, $guest] = $this->setupScopedGuest();
        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        // A member sees exactly their teams: both projects here.
        $inScopeIssue->project->projectMembers()->create(['user_id' => $member->id, 'role' => 'member']);
        $outOfScopeIssue->project->projectMembers()->create(['user_id' => $member->id, 'role' => 'member']);

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

    public function test_issue_creation_only_accepts_roster_members_of_the_target_project(): void
    {
        [$owner, $workspace, $inScopeIssue, $outOfScopeIssue, $guest] = $this->setupScopedGuest();
        $project = $inScopeIssue->project;

        // A contributor of the project (roster line) creating in it.
        $contributor = User::factory()->create();
        Membership::create([
            'user_id' => $contributor->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $contributor->id,
            'role' => 'member',
        ]);

        // A workspace member who is NOT on the target project's roster:
        // assignable = a team line (D6) — an out-of-team assignee could
        // not even see the ticket (D2).
        $outsider = User::factory()->create();
        Membership::create([
            'user_id' => $outsider->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        $this->actingAs($contributor)
            ->post('/issues', [
                'project_id' => $project->id,
                'title' => 'Avec un hors équipe',
                'assignees' => [$outsider->id],
            ])
            ->assertSessionHasErrors('assignees.0');

        $this->assertDatabaseMissing('issues', ['title' => 'Avec un hors équipe']);

        // The same creation with a roster member goes through.
        $this->actingAs($contributor)
            ->post('/issues', [
                'project_id' => $project->id,
                'title' => 'Avec un équipier',
                'assignees' => [$guest->id],
            ])
            ->assertRedirect();

        $issue = $workspace->issues()->withoutGlobalScope(WorkspaceScope::class)->where('title', 'Avec un équipier')->firstOrFail();

        $this->assertSame([$guest->id], $issue->assignees()->pluck('users.id')->all());
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

    // ── Invité projet : lecture + commentaires + temps, aucune écriture ─

    /**
     * A workspace member carrying the roster role Guest on the issue's
     * project: the project-role perimeter (T2). Whatever their workspace
     * role, a project guest reads, comments, logs time and stays
     * assignable — but never writes the issue.
     *
     * @return array{0: User, 1: Workspace, 2: Issue, 3: User}
     */
    private function setupProjectGuest(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $project = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Ticket equipe']);

        $projectGuest = User::factory()->create();
        Membership::create([
            'user_id' => $projectGuest->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $projectGuest->id,
            'role' => 'guest',
        ]);

        return [$owner, $workspace, $issue, $projectGuest];
    }

    public function test_project_guest_reads_the_issue_detail_page(): void
    {
        [$owner, $workspace, $issue, $projectGuest] = $this->setupProjectGuest();

        $this->actingAs($projectGuest)
            ->get(route('issues.show', $issue->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('issue.id', $issue->id)
                ->where('canContribute', false));
    }

    public function test_project_guest_cannot_create_an_issue(): void
    {
        [$owner, $workspace, $issue, $projectGuest] = $this->setupProjectGuest();

        $this->actingAs($projectGuest)
            ->post(route('issues.store'), [
                'project_id' => $issue->project_id,
                'title' => 'Ticket interdit',
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('issues', ['title' => 'Ticket interdit']);
    }

    public function test_project_guest_cannot_update_an_issue(): void
    {
        [$owner, $workspace, $issue, $projectGuest] = $this->setupProjectGuest();

        $this->actingAs($projectGuest)
            ->patch(route('issues.update', $issue->id), ['title' => 'Pirate'])
            ->assertNotFound();

        $this->assertDatabaseHas('issues', ['id' => $issue->id, 'title' => 'Ticket equipe']);
    }

    public function test_project_guest_cannot_delete_an_issue(): void
    {
        [$owner, $workspace, $issue, $projectGuest] = $this->setupProjectGuest();

        $this->actingAs($projectGuest)
            ->delete(route('issues.destroy', $issue->id))
            ->assertNotFound();

        $this->assertDatabaseHas('issues', ['id' => $issue->id]);
    }

    public function test_project_guest_cannot_move_an_issue(): void
    {
        [$owner, $workspace, $issue, $projectGuest] = $this->setupProjectGuest();
        $status = $workspace->statuses()->first();

        $this->actingAs($projectGuest)
            ->post(route('issues.move', $issue->id), ['status_id' => $status->id])
            ->assertNotFound();
    }

    public function test_project_guest_cannot_set_labels(): void
    {
        [$owner, $workspace, $issue, $projectGuest] = $this->setupProjectGuest();

        $this->actingAs($projectGuest)
            ->post(route('issues.labels', $issue->id), ['label_ids' => []])
            ->assertNotFound();
    }

    public function test_project_guest_cannot_assign(): void
    {
        [$owner, $workspace, $issue, $projectGuest] = $this->setupProjectGuest();

        // A valid roster target: the project owner. The refusal is about
        // the actor's role, not the target.
        $this->actingAs($projectGuest)
            ->post(route('issues.assignees', $issue->id), ['assignees' => [$owner->id]])
            ->assertNotFound();

        $this->assertDatabaseMissing('issue_assignees', [
            'issue_id' => $issue->id,
            'user_id' => $owner->id,
        ]);
    }

    public function test_project_guest_cannot_set_a_parent(): void
    {
        [$owner, $workspace, $issue, $projectGuest] = $this->setupProjectGuest();

        $this->actingAs($projectGuest)
            ->post(route('issues.parent', $issue->id), ['parent_id' => null])
            ->assertNotFound();
    }

    public function test_project_guest_cannot_set_dependencies(): void
    {
        [$owner, $workspace, $issue, $projectGuest] = $this->setupProjectGuest();

        $this->actingAs($projectGuest)
            ->post(route('issues.dependencies', $issue->id), ['blocked_by' => []])
            ->assertNotFound();
    }

    public function test_project_guest_cannot_set_the_estimate(): void
    {
        [$owner, $workspace, $issue, $projectGuest] = $this->setupProjectGuest();

        $this->actingAs($projectGuest)
            ->post(route('issues.estimate', $issue->id), ['estimated_hours' => 2])
            ->assertNotFound();

        $this->assertDatabaseHas('issues', ['id' => $issue->id, 'estimated_hours' => null]);
    }

    public function test_project_guest_cannot_toggle_auto_completion(): void
    {
        [$owner, $workspace, $issue, $projectGuest] = $this->setupProjectGuest();

        $this->actingAs($projectGuest)
            ->post(route('issues.auto-complete', $issue->id), ['enabled' => true])
            ->assertNotFound();

        $this->assertDatabaseHas('issues', ['id' => $issue->id, 'auto_complete_children' => false]);
    }

    public function test_project_guest_cannot_link_knowledge_pages(): void
    {
        [$owner, $workspace, $issue, $projectGuest] = $this->setupProjectGuest();

        $this->actingAs($projectGuest)
            ->post(route('issues.knowledge', $issue->id), ['page_ids' => []])
            ->assertNotFound();
    }

    public function test_project_guest_cannot_set_the_milestone(): void
    {
        [$owner, $workspace, $issue, $projectGuest] = $this->setupProjectGuest();

        $this->actingAs($projectGuest)
            ->post(route('issues.milestone', $issue->id), ['milestone_id' => null])
            ->assertNotFound();
    }

    public function test_project_guest_can_comment(): void
    {
        [$owner, $workspace, $issue, $projectGuest] = $this->setupProjectGuest();

        $this->actingAs($projectGuest)
            ->post(route('comments.store', $issue->id), ['body' => 'Note du guest projet'])
            ->assertRedirect();

        $this->assertDatabaseHas('comments', [
            'issue_id' => $issue->id,
            'body' => 'Note du guest projet',
        ]);
    }

    public function test_project_guest_can_log_time(): void
    {
        [$owner, $workspace, $issue, $projectGuest] = $this->setupProjectGuest();

        $this->actingAs($projectGuest)
            ->post(route('issues.time-entries.store', $issue->id), ['hours' => 1.5])
            ->assertRedirect();

        $this->assertDatabaseHas('time_entries', [
            'issue_id' => $issue->id,
            'author_id' => $projectGuest->id,
        ]);
    }

    public function test_project_guest_can_fill_the_week_timesheet(): void
    {
        [$owner, $workspace, $issue, $projectGuest] = $this->setupProjectGuest();

        $monday = now()->startOfWeek()->toDateString();

        $this->actingAs($projectGuest)
            ->postJson(route('time-entries.week.store'), [
                'week' => $monday,
                'lines' => [[
                    'issueId' => $issue->id,
                    'note' => null,
                    'days' => [$monday => 2],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('created', 1);

        $this->assertDatabaseHas('time_entries', [
            'issue_id' => $issue->id,
            'author_id' => $projectGuest->id,
            'hours' => 2,
        ]);
    }

    public function test_project_guest_is_assignable(): void
    {
        [$owner, $workspace, $issue, $projectGuest] = $this->setupProjectGuest();

        // A roster line makes the project guest a legitimate assignee
        // target, whoever assigns (here the workspace owner).
        SetAssignees::run($issue, [$projectGuest->id], $owner);

        $this->assertDatabaseHas('issue_assignees', [
            'issue_id' => $issue->id,
            'user_id' => $projectGuest->id,
        ]);
    }

    public function test_project_guest_issues_index_hides_the_create_button(): void
    {
        [$owner, $workspace, $issue, $projectGuest] = $this->setupProjectGuest();

        $this->actingAs($projectGuest)
            ->get(route('issues.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canCreateIssue', false));
    }

    public function test_project_guest_mcp_create_issue_is_not_found(): void
    {
        [$owner, $workspace, $issue, $projectGuest] = $this->setupProjectGuest();

        $response = TaskrServer::actingAs($projectGuest)->tool(CreateIssueTool::class, [
            'workspace_id' => $workspace->id,
            'project_id' => $issue->project_id,
            'title' => 'Ticket interdit',
        ]);

        $this->assertSame('not_found', $response->payload()['error']);
        $this->assertDatabaseMissing('issues', ['title' => 'Ticket interdit']);
    }

    public function test_project_guest_mcp_update_issue_is_not_found(): void
    {
        [$owner, $workspace, $issue, $projectGuest] = $this->setupProjectGuest();

        $response = TaskrServer::actingAs($projectGuest)->tool(UpdateIssueTool::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $issue->id,
            'title' => 'Pirate',
        ]);

        $this->assertSame('not_found', $response->payload()['error']);
        $this->assertDatabaseHas('issues', ['id' => $issue->id, 'title' => 'Ticket equipe']);
    }

    // ── Orthogonalité : le rôle ws ne décide plus des droits projet ──

    public function test_workspace_guest_roster_manager_can_create_an_issue(): void
    {
        [$owner, $workspace, $issue, $projectGuest] = $this->setupProjectGuest();

        // D7: a workspace guest promoted project manager exercises the
        // project role fully, whatever their workspace role.
        $workspaceGuest = User::factory()->create();
        Membership::create([
            'user_id' => $workspaceGuest->id,
            'workspace_id' => $workspace->id,
            'role' => 'guest',
        ]);
        ProjectMember::create([
            'project_id' => $issue->project_id,
            'user_id' => $workspaceGuest->id,
            'role' => 'manager',
        ]);

        $this->actingAs($workspaceGuest)
            ->post(route('issues.store'), [
                'project_id' => $issue->project_id,
                'title' => 'Ticket du manager invite',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('issues', ['title' => 'Ticket du manager invite']);
    }
}

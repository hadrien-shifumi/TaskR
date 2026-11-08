<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\TimeTracking\StartTimer;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\StatusCategory;
use App\Models\ActiveTimer;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\ProjectMember;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimerTest extends TestCase
{
    use RefreshDatabase;

    private function setupIssue(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);
        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Page de profil']);

        return [$owner, $workspace, $issue];
    }

    private function statusOf($workspace, StatusCategory $category): Status
    {
        return Status::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('category', $category)
            ->firstOrFail();
    }

    // ── Start ────────────────────────────────────────────────────────

    public function test_user_can_start_a_timer(): void
    {
        [$owner, , $issue] = $this->setupIssue();

        $this->actingAs($owner)
            ->postJson(route('issues.timer.start', $issue->id))
            ->assertOk()
            ->assertJsonStructure(['timer' => ['id', 'issueId', 'issueTitle', 'startedAt', 'elapsedHours', 'exceeds24h']])
            ->assertJsonPath('timer.issueTitle', 'Page de profil');

        $this->assertDatabaseHas('active_timers', [
            'user_id' => $owner->id,
            'workspace_id' => app(WorkspaceContext::class)->get()->id,
            'issue_id' => $issue->id,
        ]);
    }

    public function test_starting_a_second_timer_stops_the_first_and_proposes_elapsed_time(): void
    {
        [$owner, $workspace, $issue] = $this->setupIssue();

        StartTimer::run($issue, $owner);
        $this->travel(90)->minutes();

        $otherProject = CreateProject::run($workspace, $owner, ['name' => 'Autre projet']);
        $otherIssue = CreateIssue::run($workspace, ['project_id' => $otherProject->id, 'title' => 'Autre ticket']);

        $this->actingAs($owner)
            ->postJson(route('issues.timer.start', $otherIssue->id))
            ->assertOk()
            ->assertJsonPath('stopped.roundedHours', 1.5)
            ->assertJsonPath('stopped.issueId', $issue->id);

        $this->assertSame(1, ActiveTimer::withoutWorkspaceScope()->where('user_id', $owner->id)->count());
        $this->assertDatabaseHas('active_timers', ['user_id' => $owner->id, 'issue_id' => $otherIssue->id]);
    }

    // ── 0.01 h floor ────────────────────────────────────────────────

    public function test_a_few_seconds_timer_proposes_the_0_01_floor(): void
    {
        [$owner, , $issue] = $this->setupIssue();

        StartTimer::run($issue, $owner);
        $this->travel(30)->seconds();

        $this->actingAs($owner)
            ->postJson(route('timer.stop'))
            ->assertOk()
            ->assertJsonPath('stopped.roundedHours', 0.01);
    }

    // ── 47 min → rounded to the hundredth ───────────────────────────

    public function test_elapsed_time_is_rounded_to_the_hundredth(): void
    {
        [$owner, , $issue] = $this->setupIssue();

        StartTimer::run($issue, $owner);
        $this->travel(47)->minutes();

        $this->actingAs($owner)
            ->postJson(route('timer.stop'))
            ->assertOk()
            ->assertJsonPath('stopped.roundedHours', 0.78);
    }

    // ── 24 h guard ──────────────────────────────────────────────────

    public function test_stop_flags_a_timer_running_for_more_than_24_hours(): void
    {
        [$owner, , $issue] = $this->setupIssue();

        StartTimer::run($issue, $owner);
        $this->travel(26)->hours();

        $this->actingAs($owner)
            ->postJson(route('timer.stop'))
            ->assertOk()
            ->assertJsonPath('stopped.exceeds24h', true);

        // Counter-case: a fresh two-hour timer is never flagged.
        StartTimer::run($issue, $owner);
        $this->travel(2)->hours();

        $this->actingAs($owner)
            ->postJson(route('timer.stop'))
            ->assertOk()
            ->assertJsonPath('stopped.exceeds24h', false);
    }

    // ── Done / cancelled issues refuse to be timed ──────────────────

    public function test_timer_cannot_start_on_a_done_issue(): void
    {
        [$owner, $workspace, $issue] = $this->setupIssue();
        $issue->update(['status_id' => $this->statusOf($workspace, StatusCategory::Done)->id]);

        $this->actingAs($owner)
            ->postJson(route('issues.timer.start', $issue->id))
            ->assertStatus(422);
    }

    public function test_timer_cannot_start_on_a_cancelled_issue(): void
    {
        [$owner, $workspace, $issue] = $this->setupIssue();
        $issue->update(['status_id' => $this->statusOf($workspace, StatusCategory::Cancelled)->id]);

        $this->actingAs($owner)
            ->postJson(route('issues.timer.start', $issue->id))
            ->assertStatus(422);
    }

    // ── Workspace isolation ─────────────────────────────────────────

    public function test_active_timer_is_per_workspace(): void
    {
        [$owner, $workspace, $issue] = $this->setupIssue();

        StartTimer::run($issue, $owner);

        $otherWorkspace = CreateWorkspace::run(User::factory()->create(), ['name' => 'Autre WS']);
        Membership::create(['user_id' => $owner->id, 'workspace_id' => $otherWorkspace->id, 'role' => 'member']);

        $this->actingAs($owner)
            ->withSession(['workspace_id' => $otherWorkspace->id])
            ->getJson(route('timer.show'))
            ->assertOk()
            ->assertJsonPath('timer', null);
    }

    public function test_timer_start_on_issue_of_another_workspace_is_not_found(): void
    {
        [$owner, $workspace, $issue] = $this->setupIssue();

        $otherOwner = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($otherOwner, ['name' => 'Autre WS']);
        app(WorkspaceContext::class)->set($otherWorkspace);
        $otherProject = CreateProject::run($otherWorkspace, $otherOwner, ['name' => 'Autre projet']);
        $otherIssue = CreateIssue::run($otherWorkspace, ['project_id' => $otherProject->id, 'title' => 'Hors de portée']);

        app(WorkspaceContext::class)->set($workspace);

        $this->actingAs($owner)
            ->postJson(route('issues.timer.start', $otherIssue->id))
            ->assertNotFound();
    }

    // ── Guest perimeter ─────────────────────────────────────────────

    public function test_guest_cannot_start_a_timer_out_of_scope(): void
    {
        [$owner, $workspace, , $outOfScopeIssue, $guest] = $this->setupScopedGuest();

        $this->actingAs($guest)
            ->postJson(route('issues.timer.start', $outOfScopeIssue->id))
            ->assertNotFound();
    }

    public function test_guest_can_start_a_timer_within_scope(): void
    {
        [$owner, $workspace, $inScopeIssue, , $guest] = $this->setupScopedGuest();

        $this->actingAs($guest)
            ->postJson(route('issues.timer.start', $inScopeIssue->id))
            ->assertOk()
            ->assertJsonPath('timer.issueId', $inScopeIssue->id);

        $this->assertDatabaseHas('active_timers', [
            'user_id' => $guest->id,
            'workspace_id' => $workspace->id,
            'issue_id' => $inScopeIssue->id,
        ]);
    }

    // ── Server-side rehydration ─────────────────────────────────────

    public function test_active_timer_state_is_served_by_the_server(): void
    {
        [$owner, , $issue] = $this->setupIssue();

        StartTimer::run($issue, $owner);

        $this->actingAs($owner)
            ->getJson(route('timer.show'))
            ->assertOk()
            ->assertJsonPath('timer.issueId', $issue->id)
            ->assertJsonPath('timer.issueTitle', 'Page de profil')
            ->assertJsonPath('timer.startedAt', fn (string $startedAt) => $startedAt !== '');
    }

    // ── Cascade ─────────────────────────────────────────────────────

    public function test_deleting_the_issue_cascades_the_active_timer(): void
    {
        [$owner, , $issue] = $this->setupIssue();

        StartTimer::run($issue, $owner);
        $this->assertDatabaseHas('active_timers', ['issue_id' => $issue->id]);

        $issue->delete();

        $this->assertDatabaseMissing('active_timers', ['issue_id' => $issue->id]);
    }

    // ── Standard-entry confirmation flow ────────────────────────────

    public function test_stopped_proposal_can_be_confirmed_as_a_standard_entry(): void
    {
        [$owner, , $issue] = $this->setupIssue();

        StartTimer::run($issue, $owner);
        $this->travel(150)->minutes();

        $this->actingAs($owner)
            ->postJson(route('timer.stop'))
            ->assertOk()
            ->assertJsonPath('stopped.roundedHours', 2.5);

        $this->actingAs($owner)
            ->post(route('issues.time-entries.store', $issue->id), [
                'hours' => 2.5,
                'worked_at' => now()->toDateString(),
                'note' => 'from timer',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('time_entries', [
            'issue_id' => $issue->id,
            'author_id' => $owner->id,
            'hours' => 2.5,
        ]);

        $this->assertDatabaseMissing('active_timers', ['user_id' => $owner->id]);
    }

    /**
     * Owner + workspace + project + issue, plus a guest scoped to the
     * in-scope project only.
     *
     * @return array{0: User, 1: Workspace, 2: Issue, 3: User, 4: Issue, 5: Issue}
     */
    private function setupScopedGuest(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $inScopeProject = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $outOfScopeProject = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

        $inScopeIssue = CreateIssue::run($workspace, ['project_id' => $inScopeProject->id, 'title' => 'Dans le perimetre']);
        $outOfScopeIssue = CreateIssue::run($workspace, ['project_id' => $outOfScopeProject->id, 'title' => 'Hors perimetre']);

        $guest = User::factory()->create();
        Membership::create(['user_id' => $guest->id, 'workspace_id' => $workspace->id, 'role' => 'guest']);
        ProjectMember::create(['project_id' => $inScopeProject->id, 'user_id' => $guest->id, 'role' => 'member']);

        return [$owner, $workspace, $inScopeIssue, $outOfScopeIssue, $guest];
    }
}

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
            ->assertJsonStructure(['timer' => ['id', 'issueId', 'issueTitle', 'startedAt', 'elapsedHours', 'exceeds24h'], 'stopped'])
            ->assertJsonPath('timer.issueTitle', 'Page de profil')
            ->assertJsonPath('stopped', []);

        $this->assertDatabaseHas('active_timers', [
            'user_id' => $owner->id,
            'workspace_id' => app(WorkspaceContext::class)->get()->id,
            'issue_id' => $issue->id,
        ]);
    }

    public function test_a_second_timer_runs_in_parallel_by_default(): void
    {
        [$owner, $workspace, $issue] = $this->setupIssue();

        StartTimer::run($issue, $owner);
        $this->travel(90)->minutes();

        $otherProject = CreateProject::run($workspace, $owner, ['name' => 'Autre projet']);
        $otherIssue = CreateIssue::run($workspace, ['project_id' => $otherProject->id, 'title' => 'Autre ticket']);

        $this->actingAs($owner)
            ->postJson(route('issues.timer.start', $otherIssue->id))
            ->assertOk()
            ->assertJsonPath('stopped', [])
            ->assertJsonPath('timer.issueId', $otherIssue->id);

        $this->assertSame(2, ActiveTimer::withoutWorkspaceScope()->where('user_id', $owner->id)->count());
        $this->assertDatabaseHas('active_timers', ['user_id' => $owner->id, 'issue_id' => $issue->id]);
        $this->assertDatabaseHas('active_timers', ['user_id' => $owner->id, 'issue_id' => $otherIssue->id]);
    }

    public function test_starting_with_stop_previous_stops_all_running_timers_and_proposes_their_elapsed_time(): void
    {
        [$owner, $workspace, $issue] = $this->setupIssue();

        StartTimer::run($issue, $owner);
        $this->travel(90)->minutes();

        $otherProject = CreateProject::run($workspace, $owner, ['name' => 'Autre projet']);
        $otherIssue = CreateIssue::run($workspace, ['project_id' => $otherProject->id, 'title' => 'Autre ticket']);
        StartTimer::run($otherIssue, $owner);
        $this->travel(30)->minutes();

        $thirdIssue = CreateIssue::run($workspace, ['project_id' => $otherProject->id, 'title' => 'Troisième ticket']);

        $this->actingAs($owner)
            ->postJson(route('issues.timer.start', $thirdIssue->id), ['stop_previous' => true])
            ->assertOk()
            ->assertJsonCount(2, 'stopped')
            ->assertJsonPath('stopped.0.issueId', $issue->id)
            ->assertJsonPath('stopped.0.roundedHours', fn (float $hours) => abs($hours - 2.0) < 0.01)
            ->assertJsonPath('stopped.1.issueId', $otherIssue->id)
            ->assertJsonPath('stopped.1.roundedHours', fn (float $hours) => abs($hours - 0.5) < 0.01);

        $this->assertSame(1, ActiveTimer::withoutWorkspaceScope()->where('user_id', $owner->id)->count());
        $this->assertDatabaseHas('active_timers', ['user_id' => $owner->id, 'issue_id' => $thirdIssue->id]);
    }

    public function test_starting_a_timer_on_an_already_timed_issue_is_refused(): void
    {
        [$owner, , $issue] = $this->setupIssue();

        StartTimer::run($issue, $owner);

        // Refused without and with the explicit stop request: the duplicate
        // guard fires before anything is stopped.
        $this->actingAs($owner)
            ->postJson(route('issues.timer.start', $issue->id))
            ->assertStatus(422)
            ->assertJsonValidationErrors('issue');

        $this->actingAs($owner)
            ->postJson(route('issues.timer.start', $issue->id), ['stop_previous' => true])
            ->assertStatus(422);

        $this->assertSame(1, ActiveTimer::withoutWorkspaceScope()->where('user_id', $owner->id)->count());
    }

    // ── Targeted stop ───────────────────────────────────────────────

    public function test_stop_targets_one_timer_among_several(): void
    {
        [$owner, $workspace, $issue] = $this->setupIssue();

        $first = StartTimer::run($issue, $owner)['timer'];
        $this->travel(47)->minutes();

        $otherProject = CreateProject::run($workspace, $owner, ['name' => 'Autre projet']);
        $otherIssue = CreateIssue::run($workspace, ['project_id' => $otherProject->id, 'title' => 'Autre ticket']);
        StartTimer::run($otherIssue, $owner);

        $this->actingAs($owner)
            ->postJson(route('timer.stop'), ['timer_id' => $first->id])
            ->assertOk()
            ->assertJsonCount(1, 'stopped')
            ->assertJsonPath('stopped.0.issueId', $issue->id)
            ->assertJsonPath('stopped.0.roundedHours', 0.78);

        $this->assertDatabaseMissing('active_timers', ['id' => $first->id]);
        $this->assertDatabaseHas('active_timers', ['user_id' => $owner->id, 'issue_id' => $otherIssue->id]);
    }

    public function test_stop_without_target_is_refused_when_several_timers_run(): void
    {
        [$owner, $workspace, $issue] = $this->setupIssue();

        StartTimer::run($issue, $owner);

        $otherProject = CreateProject::run($workspace, $owner, ['name' => 'Autre projet']);
        $otherIssue = CreateIssue::run($workspace, ['project_id' => $otherProject->id, 'title' => 'Autre ticket']);
        StartTimer::run($otherIssue, $owner);

        $this->actingAs($owner)
            ->postJson(route('timer.stop'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('timer');

        $this->assertSame(2, ActiveTimer::withoutWorkspaceScope()->where('user_id', $owner->id)->count());
    }

    public function test_stop_cannot_target_another_users_timer(): void
    {
        [$owner, $workspace, $issue] = $this->setupIssue();

        $colleague = User::factory()->create();
        Membership::create(['user_id' => $colleague->id, 'workspace_id' => $workspace->id, 'role' => 'member']);
        $colleaguesTimer = StartTimer::run($issue, $colleague)['timer'];

        $this->actingAs($owner)
            ->postJson(route('timer.stop'), ['timer_id' => $colleaguesTimer->id])
            ->assertNotFound();

        $this->assertDatabaseHas('active_timers', ['id' => $colleaguesTimer->id]);
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
            ->assertJsonPath('stopped.0.roundedHours', 0.01);
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
            ->assertJsonPath('stopped.0.roundedHours', 0.78);
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
            ->assertJsonPath('stopped.0.exceeds24h', true);

        // Counter-case: a fresh two-hour timer is never flagged.
        StartTimer::run($issue, $owner);
        $this->travel(2)->hours();

        $this->actingAs($owner)
            ->postJson(route('timer.stop'))
            ->assertOk()
            ->assertJsonPath('stopped.0.exceeds24h', false);
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
            ->assertJsonPath('timers', []);
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

    public function test_stop_previous_only_stops_the_acting_users_timers(): void
    {
        [$owner, $workspace, $issue] = $this->setupIssue();

        $colleague = User::factory()->create();
        Membership::create(['user_id' => $colleague->id, 'workspace_id' => $workspace->id, 'role' => 'member']);
        $colleaguesTimer = StartTimer::run($issue, $colleague)['timer'];

        $otherProject = CreateProject::run($workspace, $owner, ['name' => 'Autre projet']);
        $otherIssue = CreateIssue::run($workspace, ['project_id' => $otherProject->id, 'title' => 'Autre ticket']);

        $this->actingAs($owner)
            ->postJson(route('issues.timer.start', $otherIssue->id), ['stop_previous' => true])
            ->assertOk()
            ->assertJsonPath('stopped', []);

        $this->assertDatabaseHas('active_timers', ['id' => $colleaguesTimer->id]);
    }

    // ── Server-side rehydration ─────────────────────────────────────

    public function test_active_timer_state_is_served_by_the_server(): void
    {
        [$owner, $workspace, $issue] = $this->setupIssue();

        StartTimer::run($issue, $owner);

        $otherProject = CreateProject::run($workspace, $owner, ['name' => 'Autre projet']);
        $otherIssue = CreateIssue::run($workspace, ['project_id' => $otherProject->id, 'title' => 'Autre ticket']);
        StartTimer::run($otherIssue, $owner);
        $this->travel(30)->minutes();

        $this->actingAs($owner)
            ->getJson(route('timer.show'))
            ->assertOk()
            ->assertJsonCount(2, 'timers')
            ->assertJsonPath('timers.0.issueId', $issue->id)
            ->assertJsonPath('timers.0.issueTitle', 'Page de profil')
            ->assertJsonPath('timers.0.startedAt', fn (string $startedAt) => $startedAt !== '')
            ->assertJsonPath('timers.0.elapsedHours', fn (float $elapsedHours) => abs($elapsedHours - 0.5) < 0.01)
            ->assertJsonPath('timers.1.issueId', $otherIssue->id);
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
            ->assertJsonPath('stopped.0.roundedHours', 2.5);

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

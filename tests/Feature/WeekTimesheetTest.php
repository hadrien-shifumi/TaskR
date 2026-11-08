<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\TimeTracking\CreateTimeEntry;
use App\Actions\TimeTracking\StoreWeekTimesheet;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\AuditEvent;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\ProjectMember;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WeekTimesheetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Freeze the clock on Wednesday 2026-11-04 15:00: the current week
     * runs from Monday 2026-11-02 to Sunday 2026-11-08, and the 8-week
     * retroactivity floor is Monday 2026-10-05.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-04 15:00:00'));
    }

    /**
     * A workspace with one project, its owner and an issue.
     *
     * @return array{0: User, 1: Workspace, 2: Issue}
     */
    private function setupIssue(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);
        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Page de profil']);

        return [$owner, $workspace, $issue];
    }

    /**
     * A guest scoped to the "Alpha" project, with an in-scope and an
     * out-of-scope issue.
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

    /**
     * A plain member of the workspace owning the given issue.
     */
    private function memberOf(Issue $issue): User
    {
        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $issue->workspace_id,
            'role' => 'member',
        ]);

        return $member;
    }

    /**
     * The current week (Monday 2026-11-02) grid payload for one issue.
     *
     * @param  array<string, float>  $days
     * @return array<string, mixed>
     */
    private function grid(Issue $issue, array $days, ?string $note = null, string $week = '2026-11-02'): array
    {
        return [
            'week' => $week,
            'lines' => [[
                'issueId' => $issue->id,
                'note' => $note,
                'days' => $days,
            ]],
        ];
    }

    // ── 1. Validation en une passe ──────────────────────────────────

    public function test_storing_the_grid_creates_one_entry_per_non_empty_cell(): void
    {
        [$owner, , $issue] = $this->setupIssue();

        $this->actingAs($owner)
            ->postJson(route('time-entries.week.store'), $this->grid(
                $issue,
                ['2026-11-02' => 2.5, '2026-11-03' => 1.25, '2026-11-05' => 0.75],
                'sprint 12',
            ))
            ->assertOk()
            ->assertJsonPath('created', 3)
            ->assertJsonCount(3, 'entries');

        $this->assertDatabaseCount('time_entries', 3);

        $entries = TimeEntry::query()->orderBy('worked_at')->get();
        $this->assertSame(['2026-11-02', '2026-11-03', '2026-11-05'], $entries->pluck('worked_at')->map->toDateString()->all());
        $this->assertSame(['2.50', '1.25', '0.75'], $entries->pluck('hours')->all());
        $this->assertSame('sprint 12', $entries->pluck('note')->unique()->first());
        $this->assertSame($owner->id, $entries->pluck('author_id')->unique()->first());
        $this->assertSame($issue->id, $entries->pluck('issue_id')->unique()->first());

        // One audit event per created entry, on the parent ticket.
        $this->assertSame(3, AuditEvent::query()
            ->where('type', 'time_entry.created')
            ->where('issue_id', $issue->id)
            ->count());
    }

    public function test_empty_cells_are_ignored(): void
    {
        [$owner, , $issue] = $this->setupIssue();

        // Monday and Wednesday filled: Tuesday stays empty, no 0 entry.
        $this->actingAs($owner)
            ->postJson(route('time-entries.week.store'), $this->grid(
                $issue,
                ['2026-11-02' => 2.5, '2026-11-04' => 1.5],
            ))
            ->assertOk()
            ->assertJsonPath('created', 2);

        $this->assertDatabaseCount('time_entries', 2);
        $this->assertDatabaseMissing('time_entries', ['issue_id' => $issue->id, 'worked_at' => '2026-11-03']);

        // No note on the line: the entries carry none.
        $this->assertDatabaseHas('time_entries', ['issue_id' => $issue->id, 'note' => null]);
    }

    public function test_zero_hours_cell_is_rejected(): void
    {
        [$owner, , $issue] = $this->setupIssue();

        $this->actingAs($owner)
            ->postJson(route('time-entries.week.store'), $this->grid($issue, ['2026-11-02' => 0]))
            ->assertJsonValidationErrors(['lines.0.days.2026-11-02']);

        $this->assertDatabaseCount('time_entries', 0);
    }

    public function test_invalid_cell_creates_nothing(): void
    {
        [$owner, , $issue] = $this->setupIssue();

        // One valid line, one invalid cell: nothing is created at all.
        $payload = $this->grid($issue, ['2026-11-02' => 2.5]);
        $payload['lines'][] = ['issueId' => $issue->id, 'note' => null, 'days' => ['2026-11-03' => -1]];

        $this->actingAs($owner)
            ->postJson(route('time-entries.week.store'), $payload)
            ->assertJsonValidationErrors(['lines.1.days.2026-11-03']);

        $this->assertDatabaseCount('time_entries', 0);
    }

    public function test_out_of_scope_issue_fails_like_nonexistent(): void
    {
        [$owner, , $inScopeIssue, $outOfScopeIssue, $guest] = $this->setupScopedGuest();

        $this->actingAs($guest)
            ->postJson(route('time-entries.week.store'), $this->grid($outOfScopeIssue, ['2026-11-02' => 1.5]))
            ->assertNotFound();

        // A fabricated id fails exactly the same way.
        $fabricated = new Issue;
        $fabricated->forceFill(['id' => (string) Str::ulid()]);

        $this->actingAs($guest)
            ->postJson(route('time-entries.week.store'), $this->grid($fabricated, ['2026-11-02' => 1.5]))
            ->assertNotFound();

        $this->assertDatabaseCount('time_entries', 0);

        // The in-scope ticket stays writable by the guest.
        $this->actingAs($guest)
            ->postJson(route('time-entries.week.store'), $this->grid($inScopeIssue, ['2026-11-02' => 1.5]))
            ->assertOk();
    }

    // ── 2. Bornes de rétroactivité ─────────────────────────────────

    public function test_future_week_is_rejected(): void
    {
        [$owner, , $issue] = $this->setupIssue();

        $this->actingAs($owner)
            ->postJson(route('time-entries.week.store'), $this->grid(
                $issue,
                ['2026-11-10' => 1.5],
                week: '2026-11-09',
            ))
            ->assertJsonValidationErrors(['week']);

        $this->assertDatabaseCount('time_entries', 0);
    }

    public function test_week_older_than_8_weeks_is_rejected(): void
    {
        [$owner, , $issue] = $this->setupIssue();

        // Nine weeks back (Monday 2026-08-31): beyond the window.
        $this->actingAs($owner)
            ->postJson(route('time-entries.week.store'), $this->grid(
                $issue,
                ['2026-09-01' => 1.5],
                week: '2026-08-31',
            ))
            ->assertJsonValidationErrors(['week']);

        $this->assertDatabaseCount('time_entries', 0);
    }

    public function test_week_boundaries_accept_current_and_8_weeks_back(): void
    {
        [$owner, , $issue] = $this->setupIssue();

        // The current week: accepted.
        $this->actingAs($owner)
            ->postJson(route('time-entries.week.store'), $this->grid(
                $issue,
                ['2026-11-02' => 2.5],
                'semaine courante',
            ))
            ->assertOk()
            ->assertJsonPath('created', 1);

        // Exactly 8 weeks back (Monday 2026-09-07): accepted.
        $this->actingAs($owner)
            ->postJson(route('time-entries.week.store'), $this->grid(
                $issue,
                ['2026-09-07' => 1.5],
                'huitieme semaine en arriere',
                week: '2026-09-07',
            ))
            ->assertOk()
            ->assertJsonPath('created', 1);

        $this->assertDatabaseCount('time_entries', 2);
    }

    public function test_duplicate_cell_is_rejected(): void
    {
        [$owner, , $issue] = $this->setupIssue();
        $member = $this->memberOf($issue);

        // The dialogue already logged Monday for this author.
        CreateTimeEntry::run($issue, $member, ['hours' => 2, 'worked_at' => '2026-11-02']);

        $this->actingAs($member)
            ->postJson(route('time-entries.week.store'), $this->grid(
                $issue,
                ['2026-11-02' => 1, '2026-11-03' => 1],
            ))
            ->assertJsonValidationErrors(['lines.0.days.2026-11-02']);

        // Nothing was created, not even the valid Tuesday cell.
        $this->assertDatabaseCount('time_entries', 1);
        $this->assertDatabaseMissing('time_entries', ['issue_id' => $issue->id, 'worked_at' => '2026-11-03']);
    }

    public function test_malformed_week_format_is_rejected(): void
    {
        [$owner, , $issue] = $this->setupIssue();

        // A datetime-looking or slashed week must be refused at validation,
        // never crash the server: nothing is created.
        foreach (['2026-11-08 10:00', '2026/11/08'] as $week) {
            $this->actingAs($owner)
                ->postJson(route('time-entries.week.store'), $this->grid(
                    $issue,
                    ['2026-11-02' => 1.5],
                    week: $week,
                ))
                ->assertJsonValidationErrors(['week']);
        }

        $this->assertDatabaseCount('time_entries', 0);

        // The object path skips HTTP validation: the handler refuses a
        // malformed week all the same.
        try {
            StoreWeekTimesheet::run($owner, '2026-11-08 10:00', [[
                'issueId' => $issue->id,
                'note' => null,
                'days' => ['2026-11-02' => 1.5],
            ]]);
            $this->fail('The malformed week must be refused on the object path too.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('week', $exception->errors());
        }
    }

    // ── 3. Périmètres ───────────────────────────────────────────────

    public function test_week_timesheet_follows_the_guest_perimeter(): void
    {
        [$owner, , $inScopeIssue, $outOfScopeIssue, $guest] = $this->setupScopedGuest();

        CreateTimeEntry::run($inScopeIssue, $owner, ['hours' => 1.5, 'worked_at' => '2026-11-02']);
        CreateTimeEntry::run($outOfScopeIssue, $owner, ['hours' => 2.5, 'worked_at' => '2026-11-03']);

        // Reading: the guest has no entry of its own — an empty grid.
        $this->actingAs($guest)
            ->getJson(route('time-entries.week.show'))
            ->assertOk()
            ->assertJsonPath('weekTotal', 0)
            ->assertJsonCount(0, 'grid');

        // Writing: the out-of-scope ticket fails like a nonexistent one.
        $this->actingAs($guest)
            ->postJson(route('time-entries.week.store'), $this->grid($outOfScopeIssue, ['2026-11-02' => 1.5]))
            ->assertNotFound();

        $this->assertDatabaseCount('time_entries', 2);
    }

    public function test_week_timesheet_never_leaks_another_workspace(): void
    {
        [$owner, $workspace, $issue] = $this->setupIssue();
        CreateTimeEntry::run($issue, $owner, ['hours' => 1.5, 'worked_at' => '2026-11-02']);

        // A second workspace with its own issue and entry.
        $otherOwner = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($otherOwner, ['name' => 'Other WS']);
        app(WorkspaceContext::class)->set($otherWorkspace);
        $otherProject = CreateProject::run($otherWorkspace, $otherOwner, ['name' => 'Other project']);
        $otherIssue = CreateIssue::run($otherWorkspace, ['project_id' => $otherProject->id, 'title' => 'Other issue']);
        CreateTimeEntry::run($otherIssue, $otherOwner, ['hours' => 4, 'worked_at' => '2026-11-02']);

        // Back to the first workspace, explicitly.
        $this->withSession(['workspace_id' => $workspace->id]);

        // Reading from workspace 1 never shows workspace 2's entries.
        $this->actingAs($owner)
            ->getJson(route('time-entries.week.show'))
            ->assertOk()
            ->assertJsonPath('weekTotal', 1.5)
            ->assertJsonCount(1, 'entries');

        // Writing: workspace 2's issue fails like a nonexistent one.
        $this->actingAs($owner)
            ->postJson(route('time-entries.week.store'), $this->grid($otherIssue, ['2026-11-03' => 1]))
            ->assertNotFound();

        $this->assertDatabaseCount('time_entries', 2);
    }

    // ── 4. Lecture de la semaine ────────────────────────────────────

    public function test_the_week_reading_returns_the_grid_and_total(): void
    {
        [$owner, , $issue] = $this->setupIssue();

        CreateTimeEntry::run($issue, $owner, ['hours' => 2.5, 'worked_at' => '2026-11-02', 'note' => 'sprint 12']);
        CreateTimeEntry::run($issue, $owner, ['hours' => 1.25, 'worked_at' => '2026-11-04', 'note' => 'sprint 12']);
        // Another member's entry: never in the owner's grid.
        CreateTimeEntry::run($issue, $this->memberOf($issue), ['hours' => 9, 'worked_at' => '2026-11-03']);

        $this->actingAs($owner)
            ->getJson(route('time-entries.week.show', ['week' => '2026-11-02']))
            ->assertOk()
            ->assertJsonPath('weekStart', '2026-11-02')
            ->assertJsonPath('weekEnd', '2026-11-08')
            ->assertJsonPath('weekTotal', 3.75)
            ->assertJsonCount(1, 'grid')
            ->assertJsonPath('grid.0.issueId', $issue->id)
            ->assertJsonPath('grid.0.days.2026-11-02', 2.5)
            ->assertJsonPath('grid.0.days.2026-11-04', 1.25)
            ->assertJsonPath('grid.0.weekTotal', 3.75)
            ->assertJsonCount(2, 'entries')
            ->assertJsonPath('entries.0.workedAt', '2026-11-02')
            ->assertJsonPath('entries.0.hours', 2.5)
            ->assertJsonPath('entries.0.note', 'sprint 12');

        // Out-of-bounds weeks are refused on reading too: nine weeks back
        // and the future are refused, exactly eight weeks back passes.
        $this->actingAs($owner)
            ->getJson(route('time-entries.week.show', ['week' => '2026-08-31']))
            ->assertJsonValidationErrors(['week']);

        $this->actingAs($owner)
            ->getJson(route('time-entries.week.show', ['week' => '2026-11-09']))
            ->assertJsonValidationErrors(['week']);

        $this->actingAs($owner)
            ->getJson(route('time-entries.week.show', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertJsonPath('weekStart', '2026-09-07');

        // No week parameter: the current week.
        $this->actingAs($owner)
            ->getJson(route('time-entries.week.show'))
            ->assertOk()
            ->assertJsonPath('weekStart', '2026-11-02');
    }
}

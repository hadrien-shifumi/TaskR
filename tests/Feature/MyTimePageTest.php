<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\TimeTracking\CreateTimeEntry;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class MyTimePageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Freeze the clock on Wednesday 2026-11-04 15:00: the current week
     * runs from Monday 2026-11-02 to Sunday 2026-11-08.
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

    public function test_the_current_week_is_served_by_default(): void
    {
        [$owner, , $issue] = $this->setupIssue();
        CreateTimeEntry::run($issue, $owner, ['hours' => 2.5, 'worked_at' => '2026-11-02']);

        $this->actingAs($owner)
            ->get(route('my-time.show'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('MyTime/Index')
                ->where('weekStart', '2026-11-02')
                ->where('weekEnd', '2026-11-08')
                ->where('weekTotal', 2.5)
                ->where('grid.0.issueId', $issue->id)
                ->where('grid.0.issueTitle', 'Page de profil')
                ->where('grid.0.days.2026-11-02', 2.5)
                ->where('entries.0.workedAt', '2026-11-02')
                ->where('entries.0.hours', 2.5));
    }

    public function test_a_shared_week_url_renders_the_requested_week(): void
    {
        [$owner, , $issue] = $this->setupIssue();
        CreateTimeEntry::run($issue, $owner, ['hours' => 1.5, 'worked_at' => '2026-09-07']);

        $this->actingAs($owner)
            ->get(route('my-time.show', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('MyTime/Index')
                ->where('weekStart', '2026-09-07')
                ->where('weekEnd', '2026-09-13')
                ->where('weekTotal', 1.5)
                ->where('entries.0.workedAt', '2026-09-07'));
    }

    public function test_an_unreadable_week_falls_back_to_the_current_week_with_a_warning(): void
    {
        [$owner, , $issue] = $this->setupIssue();
        CreateTimeEntry::run($issue, $owner, ['hours' => 2.5, 'worked_at' => '2026-11-02']);

        $this->actingAs($owner)
            ->get(route('my-time.show', ['week' => 'not-a-date']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('MyTime/Index')
                ->where('weekStart', '2026-11-02')
                ->where('weekEnd', '2026-11-08')
                ->where('weekTotal', 2.5)
                ->where('warning', __('flash.time_entry.week_clamped')));
    }

    public function test_a_future_week_falls_back_to_the_current_week_with_a_warning(): void
    {
        [$owner, , $issue] = $this->setupIssue();
        CreateTimeEntry::run($issue, $owner, ['hours' => 2.5, 'worked_at' => '2026-11-02']);

        $this->actingAs($owner)
            ->get(route('my-time.show', ['week' => '2026-11-09']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('MyTime/Index')
                ->where('weekStart', '2026-11-02')
                ->where('warning', __('flash.time_entry.week_clamped')));
    }

    public function test_a_week_nine_weeks_back_falls_back_to_the_current_week_with_a_warning(): void
    {
        [$owner, , $issue] = $this->setupIssue();
        CreateTimeEntry::run($issue, $owner, ['hours' => 2.5, 'worked_at' => '2026-11-02']);

        $this->actingAs($owner)
            ->get(route('my-time.show', ['week' => '2026-08-31']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('MyTime/Index')
                ->where('weekStart', '2026-11-02')
                ->where('warning', __('flash.time_entry.week_clamped')));
    }

    public function test_a_guest_reads_only_its_perimeter(): void
    {
        [$owner, $workspace, $issue] = $this->setupIssue();
        $guest = User::factory()->create();
        Membership::create([
            'user_id' => $guest->id,
            'workspace_id' => $workspace->id,
            'role' => 'guest',
        ]);
        CreateTimeEntry::run($issue, $owner, ['hours' => 2.5, 'worked_at' => '2026-11-02']);

        $this->actingAs($guest)
            ->get(route('my-time.show'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('MyTime/Index')
                ->where('weekStart', '2026-11-02')
                ->where('weekTotal', fn (int|float $total) => (float) $total === 0.0)
                ->where('grid', [])
                ->where('entries', []));
    }

    public function test_saving_the_grid_from_the_page_stays_on_the_page_with_a_flash(): void
    {
        [$owner, , $issue] = $this->setupIssue();

        // A first GET pins the "previous URL" the redirects fall back to.
        $this->actingAs($owner)->get(route('my-time.show'));

        $this->actingAs($owner)
            ->post(route('time-entries.week.store'), [
                'week' => '2026-11-02',
                'lines' => [[
                    'issueId' => $issue->id,
                    'note' => 'sprint 12',
                    'days' => ['2026-11-02' => 2.5, '2026-11-03' => 1],
                ]],
            ])
            ->assertRedirect(route('my-time.show'))
            ->assertSessionHas('status', __('flash.time_entry.week_stored'));

        $this->assertDatabaseCount('time_entries', 2);
    }

    public function test_a_duplicate_cell_from_the_page_names_the_cell_and_keeps_the_user(): void
    {
        [$owner, , $issue] = $this->setupIssue();
        CreateTimeEntry::run($issue, $owner, ['hours' => 2, 'worked_at' => '2026-11-02']);

        $this->actingAs($owner)->get(route('my-time.show'));

        $this->actingAs($owner)
            ->post(route('time-entries.week.store'), [
                'week' => '2026-11-02',
                'lines' => [[
                    'issueId' => $issue->id,
                    'note' => null,
                    'days' => ['2026-11-02' => 1, '2026-11-03' => 1],
                ]],
            ])
            ->assertRedirect(route('my-time.show'))
            ->assertSessionHasErrors(['lines.0.days.2026-11-02']);

        // Nothing was created, not even the valid Tuesday cell.
        $this->assertDatabaseCount('time_entries', 1);
    }
}

<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\TimeTracking\CreateTimeEntry;
use App\Actions\TimeTracking\DeleteTimeEntry;
use App\Actions\TimeTracking\SetIssueEstimate;
use App\Actions\TimeTracking\UpdateTimeEntry;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class TimeTrackingTest extends TestCase
{
    use RefreshDatabase;

    private function setupIssue(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);
        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Page de profil']);

        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        return [$owner, $member, $issue, $workspace];
    }

    // ── 5.1 Estimation ──────────────────────────────────────────────

    public function test_member_can_set_an_estimate(): void
    {
        [, $member, $issue] = $this->setupIssue();

        $this->actingAs($member)
            ->from(route('issues.show', $issue->id))
            ->post(route('issues.estimate', $issue->id), ['estimated_hours' => 3.5])
            ->assertRedirect(route('issues.show', $issue->id));

        $this->assertDatabaseHas('issues', [
            'id' => $issue->id,
            'estimated_hours' => 3.5,
        ]);
    }

    public function test_member_can_reset_an_estimate(): void
    {
        [, $member, $issue] = $this->setupIssue();

        SetIssueEstimate::run($issue, 8.0);

        $this->actingAs($member)
            ->from(route('issues.show', $issue->id))
            ->post(route('issues.estimate', $issue->id), ['estimated_hours' => null])
            ->assertRedirect(route('issues.show', $issue->id));

        $this->assertDatabaseHas('issues', [
            'id' => $issue->id,
            'estimated_hours' => null,
        ]);
    }

    public function test_negative_estimate_is_rejected(): void
    {
        [, $member, $issue] = $this->setupIssue();

        $this->actingAs($member)
            ->post(route('issues.estimate', $issue->id), ['estimated_hours' => -2])
            ->assertSessionHasErrors('estimated_hours');
    }

    public function test_estimate_is_editable_by_a_simple_member(): void
    {
        [, $member, $issue] = $this->setupIssue();

        $this->actingAs($member)
            ->post(route('issues.estimate', $issue->id), ['estimated_hours' => 5])
            ->assertRedirect();

        $this->assertSame('5.00', $issue->fresh()->estimated_hours);
    }

    // ── 5.2 Time entries ────────────────────────────────────────────

    public function test_member_can_log_time(): void
    {
        [, $member, $issue] = $this->setupIssue();

        $this->actingAs($member)
            ->from(route('issues.show', $issue->id))
            ->post(route('issues.time-entries.store', $issue->id), [
                'hours' => 4,
                'worked_at' => '2026-10-14',
                'note' => 'setup CI',
            ])
            ->assertRedirect(route('issues.show', $issue->id));

        $this->assertDatabaseHas('time_entries', [
            'issue_id' => $issue->id,
            'author_id' => $member->id,
            'hours' => 4,
            'note' => 'setup CI',
        ]);

        $entry = TimeEntry::where('issue_id', $issue->id)->first();
        $this->assertNotNull($entry);
        $this->assertSame('2026-10-14', $entry->worked_at->toDateString());
    }

    public function test_zero_hours_is_rejected(): void
    {
        [, $member, $issue] = $this->setupIssue();

        $this->actingAs($member)
            ->post(route('issues.time-entries.store', $issue->id), ['hours' => 0])
            ->assertSessionHasErrors('hours');
    }

    public function test_negative_hours_is_rejected(): void
    {
        [, $member, $issue] = $this->setupIssue();

        $this->actingAs($member)
            ->post(route('issues.time-entries.store', $issue->id), ['hours' => -1])
            ->assertSessionHasErrors('hours');
    }

    public function test_worked_at_defaults_to_today(): void
    {
        [, $member, $issue] = $this->setupIssue();

        $this->actingAs($member)
            ->post(route('issues.time-entries.store', $issue->id), ['hours' => 2])
            ->assertRedirect();

        $entry = TimeEntry::where('issue_id', $issue->id)->first();
        $this->assertNotNull($entry);
        $this->assertSame(now()->toDateString(), $entry->worked_at->toDateString());
    }

    public function test_note_is_optional(): void
    {
        [, $member, $issue] = $this->setupIssue();

        $this->actingAs($member)
            ->post(route('issues.time-entries.store', $issue->id), [
                'hours' => 1.5,
                'note' => null,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('time_entries', [
            'issue_id' => $issue->id,
            'note' => null,
        ]);
    }

    // ── 5.3 Permissions ─────────────────────────────────────────────

    public function test_author_can_edit_their_time_entry(): void
    {
        [, $member, $issue] = $this->setupIssue();

        $entry = CreateTimeEntry::run($issue, $member, ['hours' => 2, 'note' => 'initial']);

        $this->actingAs($member)
            ->patch(route('issues.time-entries.update', [$issue->id, $entry->id]), [
                'hours' => 3,
                'note' => 'corrected',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('time_entries', [
            'id' => $entry->id,
            'hours' => 3,
            'note' => 'corrected',
        ]);
    }

    public function test_third_party_cannot_edit_a_time_entry(): void
    {
        [$owner, $member, $issue] = $this->setupIssue();

        $entry = CreateTimeEntry::run($issue, $member, ['hours' => 2]);

        // Even the owner cannot edit someone else's entry.
        $this->actingAs($owner)
            ->patch(route('issues.time-entries.update', [$issue->id, $entry->id]), ['hours' => 5])
            ->assertForbidden();
    }

    public function test_author_can_delete_their_time_entry(): void
    {
        [, $member, $issue] = $this->setupIssue();

        $entry = CreateTimeEntry::run($issue, $member, ['hours' => 2]);

        $this->actingAs($member)
            ->delete(route('issues.time-entries.destroy', [$issue->id, $entry->id]))
            ->assertRedirect();

        $this->assertDatabaseMissing('time_entries', ['id' => $entry->id]);
    }

    public function test_third_party_cannot_delete_a_time_entry(): void
    {
        [$owner, $member, $issue] = $this->setupIssue();

        $entry = CreateTimeEntry::run($issue, $member, ['hours' => 2]);

        $this->actingAs($owner)
            ->delete(route('issues.time-entries.destroy', [$issue->id, $entry->id]))
            ->assertForbidden();
    }

    // ── 5.4 Isolation inter-tenants ─────────────────────────────────

    public function test_issue_of_another_workspace_is_not_found(): void
    {
        [, $member, $issue] = $this->setupIssue();

        // Create a second workspace with its own issue.
        $otherOwner = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($otherOwner, ['name' => 'Other WS']);
        app(WorkspaceContext::class)->set($otherWorkspace);
        $otherProject = CreateProject::run($otherWorkspace, $otherOwner, ['name' => 'Other project']);
        $otherIssue = CreateIssue::run($otherWorkspace, ['project_id' => $otherProject->id, 'title' => 'Other issue']);

        // Switch back to the first workspace context.
        $firstWorkspace = Workspace::where('name', 'WS')->firstOrFail();
        app(WorkspaceContext::class)->set($firstWorkspace);

        // The member of workspace 1 cannot log time on an issue of workspace 2.
        $this->actingAs($member)
            ->post(route('issues.time-entries.store', $otherIssue->id), ['hours' => 1])
            ->assertNotFound();
    }

    public function test_cross_workspace_time_entry_is_inaccessible_by_id(): void
    {
        [, $member, $issue] = $this->setupIssue();
        $workspace = app(WorkspaceContext::class)->get();

        // Create an entry in workspace 1.
        $entry = CreateTimeEntry::run($issue, $member, ['hours' => 2]);

        // Create a second workspace with an issue.
        $otherOwner = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($otherOwner, ['name' => 'Other WS']);
        app(WorkspaceContext::class)->set($otherWorkspace);
        $otherProject = CreateProject::run($otherWorkspace, $otherOwner, ['name' => 'Other project']);
        $otherIssue = CreateIssue::run($otherWorkspace, ['project_id' => $otherProject->id, 'title' => 'Other issue']);

        // Add the member to the other workspace.
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $otherWorkspace->id,
            'role' => 'member',
        ]);

        // The member cannot access the entry from workspace 1 via workspace 2's issue.
        $this->actingAs($member)
            ->patch(route('issues.time-entries.update', [$otherIssue->id, $entry->id]), ['hours' => 5])
            ->assertNotFound();

        $this->actingAs($member)
            ->delete(route('issues.time-entries.destroy', [$otherIssue->id, $entry->id]))
            ->assertNotFound();
    }

    // ── 5.5 Totaux ──────────────────────────────────────────────────

    public function test_totals_are_exact_after_multiple_entries(): void
    {
        [$owner, $member, $issue] = $this->setupIssue();

        SetIssueEstimate::run($issue, 8.0);
        CreateTimeEntry::run($issue, $owner, ['hours' => 2]);
        CreateTimeEntry::run($issue, $member, ['hours' => 3]);

        $issue->refresh();

        $this->assertSame('8.00', $issue->estimated_hours);
        $this->assertSame(5.0, $issue->logged_hours);
        $this->assertSame(3.0, $issue->remaining_hours);
    }

    public function test_remaining_is_negative_on_overconsumption(): void
    {
        [$owner, , $issue] = $this->setupIssue();

        SetIssueEstimate::run($issue, 5.0);
        CreateTimeEntry::run($issue, $owner, ['hours' => 7]);

        $issue->refresh();

        $this->assertSame(7.0, $issue->logged_hours);
        $this->assertSame(-2.0, $issue->remaining_hours);
    }

    public function test_issue_without_estimate_has_no_remaining(): void
    {
        [$owner, , $issue] = $this->setupIssue();

        CreateTimeEntry::run($issue, $owner, ['hours' => 4]);

        $issue->refresh();

        $this->assertNull($issue->estimated_hours);
        $this->assertSame(4.0, $issue->logged_hours);
        $this->assertNull($issue->remaining_hours);
    }

    public function test_issue_page_exposes_time_data(): void
    {
        [$owner, $member, $issue] = $this->setupIssue();

        SetIssueEstimate::run($issue, 8.0);
        CreateTimeEntry::run($issue, $member, ['hours' => 3, 'note' => 'review']);

        $this->actingAs($owner)
            ->get(route('issues.show', $issue->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('issue.estimatedHours', 8)
                ->where('issue.loggedHours', 3)
                ->where('issue.remainingHours', 5)
                ->has('timeEntries', 1)
                ->where('timeEntries.0.hours', 3)
                ->where('timeEntries.0.authorName', $member->name)
                ->where('timeEntries.0.note', 'review'));
    }

    // ── Tests d'action AsObject ─────────────────────────────────────

    public function test_set_issue_estimate_runs_as_object(): void
    {
        [, , $issue] = $this->setupIssue();

        $updated = SetIssueEstimate::run($issue, 12.5);

        $this->assertSame('12.50', $updated->estimated_hours);
    }

    public function test_create_time_entry_runs_as_object(): void
    {
        [, $member, $issue] = $this->setupIssue();

        $entry = CreateTimeEntry::run($issue, $member, [
            'hours' => 2.5,
            'worked_at' => '2026-10-13',
            'note' => 'pair programming',
        ]);

        $this->assertInstanceOf(TimeEntry::class, $entry);
        $this->assertSame('2.50', $entry->hours);
        $this->assertSame('pair programming', $entry->note);
    }

    public function test_update_time_entry_runs_as_object(): void
    {
        [, $member, $issue] = $this->setupIssue();

        $entry = CreateTimeEntry::run($issue, $member, ['hours' => 1]);
        $updated = UpdateTimeEntry::run($entry, ['hours' => 4, 'note' => 'updated']);

        $this->assertSame('4.00', $updated->hours);
        $this->assertSame('updated', $updated->note);
    }

    public function test_delete_time_entry_runs_as_object(): void
    {
        [, $member, $issue] = $this->setupIssue();

        $entry = CreateTimeEntry::run($issue, $member, ['hours' => 1]);
        DeleteTimeEntry::run($entry);

        $this->assertDatabaseMissing('time_entries', ['id' => $entry->id]);
    }
}

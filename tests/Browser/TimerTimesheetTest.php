<?php

use App\Actions\Issues\CreateIssue;
use App\Actions\TimeTracking\CreateTimeEntry;
use App\Models\ActiveTimer;
use App\Models\Membership;
use App\Models\User;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;

/**
 * End-to-end scenarios of the Timer & weekly timesheet chantier: the
 * start/stop cycle from the issue page, the single-active-timer rule,
 * the weekly grid and the author-only permissions on entries.
 */
it('starts a timer from the issue page and validates the pre-filled proposal', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Chrono target']);

    $this->actingAs($owner);
    $page = visit('/issues/'.$issue->id);
    $page->assertNotPresent('@timer-widget');

    $page->click('@issue-timer-toggle')
        ->assertVisible('@timer-widget')
        ->assertSeeIn('@timer-widget', 'Chrono target');

    // The stop opens the confirmation dialog with the measured time.
    $page->click('@timer-widget-stop')
        ->assertVisible('@log-time-hours');
    expect((float) $page->value('@log-time-hours'))->toBeGreaterThan(0.0);

    $page->fill('@log-time-note', 'chrono run');
    $page->click('@entry-submit');

    $page->assertSee('chrono run');

    app(WorkspaceContext::class)->set($workspace);
    $entry = $issue->timeEntries()->first();
    expect($entry)->not->toBeNull()
        ->and((float) $entry->hours)->toBeGreaterThan(0.0)
        ->and($entry->author_id)->toBe($owner->id);
});

it('replaces the running timer when a second one starts on another issue', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    $first = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'First timed issue']);
    $second = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Second timed issue']);

    $this->actingAs($owner);
    $page = visit('/issues/'.$first->id);
    $page->click('@issue-timer-toggle')
        ->assertVisible('@timer-widget')
        ->assertSeeIn('@timer-widget', 'First timed issue');

    // Starting on the second issue stops the first and proposes its time.
    $page = visit('/issues/'.$second->id);
    $page->click('@issue-timer-toggle')
        ->assertVisible('@log-time-hours')
        ->assertSee('First timed issue');

    $page->fill('@log-time-note', 'replaced run');
    $page->click('@entry-submit');

    // The proposal was recorded on the first issue, the running timer is the second one.
    app(WorkspaceContext::class)->set($workspace);
    expect($first->timeEntries()->count())->toBe(1)
        ->and($first->timeEntries()->first()->note)->toBe('replaced run')
        ->and($second->timeEntries()->count())->toBe(0)
        ->and(ActiveTimer::query()->where('user_id', $owner->id)->first()->issue_id)->toBe($second->id);
});

it('fills, saves and edits a weekly timesheet line through the grid', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Feuille target']);
    $monday = CarbonImmutable::today()->startOfWeek()->toDateString();
    $tuesday = CarbonImmutable::today()->startOfWeek()->addDay()->toDateString();

    $this->actingAs($owner);
    $page = visit('/my-time');

    $page->click('@add-line')
        ->fill('@issue-search-input', 'Feuille target')
        ->click('@issue-search-result')
        ->fill('@cell-'.$monday, '3')
        ->fill('@cell-'.$tuesday, '2.5')
        ->fill('@line-note', 'grille de la semaine')
        ->click('@save-week');

    $page->assertSeeIn('@week-total', '5h30')
        ->assertVisible('@entry-row')
        ->assertSee('grille de la semaine');

    // Editing the first raw entry re-computes the week total.
    $page->click('@entry-edit')
        ->fill('@entry-edit-hours', '2')
        ->click('@entry-edit-save');

    $page->assertSeeIn('@week-total', '4h30');

    // Deleting both raw entries empties the sheet again.
    $page->script('window.confirm = () => true');
    $page->click('@entry-delete');
    $page->click('@entry-delete');

    $page->assertSeeIn('@week-total', '0h');
});

it('warns and still validates a timer left running for more than 24 hours', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Forgotten timer']);

    app(WorkspaceContext::class)->set($workspace);
    ActiveTimer::create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
        'issue_id' => $issue->id,
        'started_at' => now()->subHours(26),
    ]);

    $this->actingAs($owner);
    $page = visit('/issues/'.$issue->id);
    $page->assertVisible('@timer-widget')
        ->assertSeeIn('@timer-widget-elapsed', '26:');

    $page->click('@timer-widget-stop')
        ->assertVisible('@log-time-hours')
        ->assertSee('more than 24 hours');

    $page->fill('@log-time-note', 'forgotten run');
    $page->click('@entry-submit');

    app(WorkspaceContext::class)->set($workspace);
    $entry = $issue->timeEntries()->first();
    expect($entry)->not->toBeNull()
        ->and((float) $entry->hours)->toBeGreaterThan(24.0);
});

it('hides the edit and delete actions of another member entry', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    $member = User::factory()->create();
    Membership::create([
        'user_id' => $member->id,
        'workspace_id' => $workspace->id,
        'role' => 'member',
    ]);

    $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Shared ticket']);
    CreateTimeEntry::run($issue, $member, ['hours' => 2, 'note' => 'note de Bob']);

    $this->actingAs($owner);
    $page = visit('/issues/'.$issue->id);
    $page->assertSee('note de Bob')
        ->assertNotPresent('@entry-edit')
        ->assertNotPresent('@entry-delete');

    // The owner's own sheet stays empty: the entry belongs to the member.
    $page = visit('/my-time');
    $page->assertDontSee('note de Bob');

    $this->actingAs($member);
    $page = visit('/my-time');
    $page->assertSee('note de Bob');
});

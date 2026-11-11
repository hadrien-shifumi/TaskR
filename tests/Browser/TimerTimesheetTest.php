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
 * start/stop cycle from the issue page, the parallel-timers rule with its
 * stop-or-parallel question, the weekly grid and the author-only
 * permissions on entries.
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

    // The stop opens the confirmation dialog with the measured time,
    // pre-filled as a duration string floored to the minute ("1h45", "30m"…).
    $page->click("@timer-widget-stop-{$issue->id}")
        ->assertVisible('@log-time-hours');
    expect($page->value('@log-time-hours'))->toMatch('/^\d+h(\d{2})?$|^\d+m$/');

    $page->fill('@log-time-note', 'chrono run');
    $page->click('@entry-submit');

    $page->assertSee('chrono run');

    app(WorkspaceContext::class)->set($workspace);
    $entry = $issue->timeEntries()->first();
    expect($entry)->not->toBeNull()
        ->and((float) $entry->hours)->toBeGreaterThan(0.0)
        ->and($entry->author_id)->toBe($owner->id);
});

it('pre-fills the stop proposal as a coherent duration string, seconds dropped', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Chrono target']);

    app(WorkspaceContext::class)->set($workspace);
    ActiveTimer::create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
        'issue_id' => $issue->id,
        // 1h45m30s ago: the proposal must read "1h45" — not "1.76" nor seconds.
        'started_at' => now()->subHours(1)->subMinutes(45)->subSeconds(30),
    ]);

    $this->actingAs($owner);
    $page = visit('/issues/'.$issue->id);

    $page->click("@timer-widget-stop-{$issue->id}")
        ->assertVisible('@log-time-hours');

    expect($page->value('@log-time-hours'))->toBe('1h45');

    $page->click('@entry-submit');

    app(WorkspaceContext::class)->set($workspace);
    $entry = $issue->timeEntries()->first();
    expect($entry)->not->toBeNull()
        ->and((float) $entry->hours)->toBe(1.75);

    $page->assertNoSmoke();
});

it('asks stop-or-parallel and runs the second timer alongside the first', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    $first = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'First timed issue']);
    $second = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Second timed issue']);

    $this->actingAs($owner);
    $page = visit('/issues/'.$first->id);
    $page->click('@issue-timer-toggle')
        ->assertVisible("@timer-widget-row-{$first->id}");

    // Starting on the second issue asks what to do with the running one.
    $page = visit('/issues/'.$second->id);
    $page->click('@issue-timer-toggle')
        ->assertVisible('@timer-conflict-dialog');

    // Parallel: both timers keep running, each with its own stop button.
    $page->click('@timer-conflict-parallel')
        ->assertVisible("@timer-widget-row-{$first->id}")
        ->assertVisible("@timer-widget-row-{$second->id}");

    // Stopping the first one only opens its own proposal.
    $page->click("@timer-widget-stop-{$first->id}")
        ->assertVisible('@log-time-hours')
        ->assertSee('First timed issue');

    $page->fill('@log-time-note', 'parallel run');
    $page->click('@entry-submit');

    app(WorkspaceContext::class)->set($workspace);
    expect($first->timeEntries()->count())->toBe(1)
        ->and($first->timeEntries()->first()->note)->toBe('parallel run')
        ->and($second->timeEntries()->count())->toBe(0)
        ->and(ActiveTimer::query()->where('user_id', $owner->id)->pluck('issue_id')->all())->toBe([$second->id]);
});

it('offers to stop the running timer instead, proposing its elapsed time', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    $first = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'First timed issue']);
    $second = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Second timed issue']);

    $this->actingAs($owner);
    $page = visit('/issues/'.$first->id);
    $page->click('@issue-timer-toggle')
        ->assertVisible("@timer-widget-row-{$first->id}");

    // The explicit stop answer replaces the old silent substitution: the
    // first timer is stopped and its time proposed, the second one runs.
    $page = visit('/issues/'.$second->id);
    $page->click('@issue-timer-toggle')
        ->assertVisible('@timer-conflict-dialog')
        ->click('@timer-conflict-stop')
        ->assertVisible('@log-time-hours')
        ->assertSee('First timed issue');

    $page->fill('@log-time-note', 'stopped run');
    $page->click('@entry-submit');

    app(WorkspaceContext::class)->set($workspace);
    expect($first->timeEntries()->count())->toBe(1)
        ->and($first->timeEntries()->first()->note)->toBe('stopped run')
        ->and($second->timeEntries()->count())->toBe(0)
        ->and(ActiveTimer::query()->where('user_id', $owner->id)->pluck('issue_id')->all())->toBe([$second->id]);
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
        ->assertSeeIn("@timer-widget-elapsed-{$issue->id}", '26:');

    $page->click("@timer-widget-stop-{$issue->id}")
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

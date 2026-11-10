<?php

use App\Actions\Issues\CreateIssue;
use App\Actions\TimeTracking\CreateTimeEntry;
use App\Actions\TimeTracking\SetIssueEstimate;
use App\Models\Membership;
use App\Models\User;
use App\Support\WorkspaceContext;

it('logs, edits and deletes time entries from the UI with updated totals', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Page de profil']);
    SetIssueEstimate::run($issue, 4.0);
    $this->actingAs($owner);

    $page = visit('/issues/'.$issue->id);

    // Scenario "Logging d'une demi-journée" (time-tracking): hours + note,
    // through the "Log time" dialog of the maquette (no inline form).
    $page->click('@log-time')
        ->fill('@log-time-hours', '1.5')
        ->fill('textarea[placeholder="Note (optional)"]', 'setup CI')
        ->click('@entry-submit');

    $page->assertSee('setup CI')
        ->assertSee('2h30 left');

    // Scenario "Édition par l'auteur": correct 1.5 to 2.
    $page->click('@entry-edit')
        ->fill('@entry-edit-hours', '2')
        ->click('@entry-edit-save');

    $page->assertSee('2h left');

    // Scenario "Suppression par l'auteur".
    $page->script('window.confirm = () => true');
    $page->click('@entry-delete');

    $page->assertSee('No time logged yet.');

    app(WorkspaceContext::class)->set($workspace);
    expect($issue->timeEntries()->count())->toBe(0);
});

it('accepts duration strings like 90m or 1:30 in the hours inputs', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Page de profil']);
    SetIssueEstimate::run($issue, 4.0);
    $this->actingAs($owner);

    $page = visit('/issues/'.$issue->id);

    // "90m" is read as 1h30, shown clock-style in the summary.
    $page->click('@log-time')
        ->fill('@log-time-hours', '90m')
        ->click('@entry-submit');

    $page->assertSeeIn('@summary-logged-value', '1h30')
        ->assertSeeIn('@summary-remaining', '2h30 left');

    app(WorkspaceContext::class)->set($workspace);
    expect((float) $issue->timeEntries()->sole()->hours)->toBe(1.5);

    // The edit input understands the clock format too: 1:15 → 1h15.
    $page->click('@entry-edit')
        ->fill('@entry-edit-hours', '1:15')
        ->click('@entry-edit-save');

    $page->assertSeeIn('@summary-logged-value', '1h15');

    app(WorkspaceContext::class)->set($workspace);
    expect((float) $issue->timeEntries()->sole()->hours)->toBe(1.25);

    // Garbage is refused client-side with the hint, nothing is stored.
    $page->click('@log-time')
        ->fill('@log-time-hours', 'abc')
        ->click('@entry-submit');

    $page->assertSee('Cannot parse that duration');

    app(WorkspaceContext::class)->set($workspace);
    expect($issue->timeEntries()->count())->toBe(1);

    $page->assertNoSmoke();
});

it('shows no edit actions on another member time entry', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    $member = User::factory()->create();
    Membership::create([
        'user_id' => $member->id,
        'workspace_id' => $workspace->id,
        'role' => 'member',
    ]);

    app(WorkspaceContext::class)->set($workspace);
    $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Page de profil']);
    CreateTimeEntry::run($issue, $member, ['hours' => 2, 'note' => 'note de Bob']);
    $this->actingAs($owner);

    $page = visit('/issues/'.$issue->id);

    // Scenario "Lecture par un membre quelconque": the entry is visible,
    // but the owner gets no actions on someone else's entry.
    $page->assertSee('note de Bob');
    $page->assertNotPresent('@entry-edit');
    $page->assertNotPresent('@entry-delete');

    $page->assertNoSmoke();
});

it('shows estimate exceeded instead of negative remaining, with a consumption bar', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Page de profil']);
    SetIssueEstimate::run($issue, 4.0);
    CreateTimeEntry::run($issue, $owner, ['hours' => 1]);
    $this->actingAs($owner);

    $page = visit('/issues/'.$issue->id);

    // Scenario "Consommation à 25%": 1h logged on a 4h estimate — bar filled
    // to 25%, remaining still expressed as hours left.
    $page->assertSeeIn('@summary-remaining', '3h left')
        ->assertAriaAttribute('@time-progress', 'valuenow', '25')
        ->assertAttributeContains('@time-progress-fill', 'class', 'bg-primary');

    // Scenario "Surconsommation": logging past the estimate replaces the
    // absurd "-1h left" with a plain "Estimate exceeded" in red, and the
    // consumption bar caps at 100% and turns destructive.
    $page->click('@log-time')
        ->fill('@log-time-hours', '5')
        ->click('@entry-submit');

    $page->assertSeeIn('@summary-remaining', 'Estimate exceeded')
        ->assertDontSee('h left')
        ->assertAriaAttribute('@time-progress', 'valuenow', '100')
        ->assertAttributeContains('@time-progress-fill', 'class', 'bg-destructive')
        ->assertAttributeContains('@summary-remaining-value', 'class', 'text-destructive');

    $page->assertNoSmoke();
});

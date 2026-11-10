<?php

use App\Actions\Comments\AddComment;
use App\Actions\Issues\CreateIssue;
use App\Actions\Issues\SetIssueParent;
use App\Actions\TimeTracking\CreateTimeEntry;
use App\Actions\TimeTracking\SetIssueEstimate;
use App\Models\Membership;
use App\Models\User;
use App\Support\WorkspaceContext;

it('copies the issue identifier from the pagebar', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Page de profil']);
    $this->actingAs($owner);

    $page = visit('/issues/'.$issue->id);

    // Maquette pagebar: the short mono identifier sits next to the back button.
    $page->assertSee(substr($issue->id, 0, 7));

    // The copy button captures the full identifier and gives feedback.
    $page->script('window.__copied = null');
    $page->script('navigator.clipboard.writeText = (text) => { window.__copied = text; return Promise.resolve(); }');
    $page->click('@copy-identifier');

    $page->assertSee('Copied');
    expect($page->script('window.__copied'))->toBe($issue->id);

    $page->assertNoSmoke();
});

it('logs time through the log time dialog', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Page de profil']);
    SetIssueEstimate::run($issue, 4.0);
    $this->actingAs($owner);

    $page = visit('/issues/'.$issue->id);

    // Maquette: no inline form — logging goes through the "Log time" dialog.
    $page->assertNotPresent('@log-time-hours');

    $page->click('@log-time');
    $page->assertSee('Only the hours are required');

    $page->fill('@log-time-hours', '1.5')
        ->fill('@log-time-date', '2026-10-13')
        ->fill('@log-time-note', 'setup CI')
        ->click('@entry-submit');

    // The entry lands in the list and the 3-cell summary reflects it.
    $page->waitForEvent('networkidle');
    $page->assertSee('setup CI')
        ->assertSee('2h30 left')
        ->assertSee('Logged')
        ->assertSee('Estimated')
        ->assertSee('Remaining');

    app(WorkspaceContext::class)->set($workspace);
    $entry = $issue->timeEntries()->sole();
    expect((float) $entry->hours)->toBe(1.5)
        ->and($entry->note)->toBe('setup CI')
        ->and($entry->worked_at->toDateString())->toBe('2026-10-13');

    $page->assertNoSmoke();
});

it('edits the description inline with a markdown preview', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $issue = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Page de profil',
        'description' => 'Ancienne **description**',
    ]);
    $this->actingAs($owner);

    $page = visit('/issues/'.$issue->id);

    $page->assertSee('Ancienne');

    $page->click('@description-edit');
    $page->assertPresent('@description-edit-body');

    // The preview toggle swaps the textarea for the rendered markdown.
    $page->click('@description-preview');
    $page->assertPresent('@description-preview-view');
    $page->assertNotPresent('@description-edit-body');

    $page->click('@description-preview');
    $page->fill('@description-edit-body', 'Nouvelle **description**');
    $page->click('@description-edit-save');

    $page->waitForEvent('networkidle');

    // The old text must leave the description section, not the audit
    // timeline: the activity section keeps the before → after trace of the
    // edit (audit spec), so the dont-see is scoped to the description
    // section, which excludes [data-test="activity-section"].
    $page->assertSee('Nouvelle')
        ->assertDontSeeIn('section:has([data-test="description-edit"])', 'Ancienne');

    app(WorkspaceContext::class)->set($workspace);
    expect($issue->fresh()->description)->toBe('Nouvelle **description**');

    $page->assertNoSmoke();
});

it('refuses a parent that would create a cycle', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $parent = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Parent issue']);
    $child = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Child issue']);
    SetIssueParent::run($child, $parent->id);
    $this->actingAs($owner);

    $page = visit('/issues/'.$parent->id);

    $page->click('@parent-dialog-open');
    $page->click('@parent-select');
    $page->click('[role="option"]:has-text("Child issue")');
    $page->click('@parent-submit');

    // The 422 refusal surfaces as a dedicated banner inside the dialog.
    $page->waitForEvent('networkidle');
    $page->assertPresent('@cycle-error');
    $page->assertSee('would create a cycle');

    app(WorkspaceContext::class)->set($workspace);
    expect($parent->fresh()->parent_id)->toBeNull();

    $page->assertNoSmoke();
});

it('lists the cascade inventory before deleting an issue', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Page de profil']);
    CreateTimeEntry::run($issue, $owner, ['hours' => 1.5, 'note' => 'pairing']);
    AddComment::run($issue, $owner, 'Vu en revue');
    $child = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Child issue']);
    SetIssueParent::run($child, $issue->id);
    $this->actingAs($owner);

    $page = visit('/issues/'.$issue->id);

    $page->click('@issue-menu');
    $page->click('Delete issue…');

    // Maquette: banner-danger with the cascade inventory.
    $page->assertSee('the logged time (1h30)')
        ->assertSee('1 comment')
        ->assertSee('child issue');

    $page->click('@confirm-delete-issue');

    $page->waitForEvent('networkidle');
    $page->assertSee('Issue deleted.');

    app(WorkspaceContext::class)->set($workspace);
    expect($issue->fresh())->toBeNull()
        ->and($child->fresh()->parent_id)->toBeNull()
        ->and($issue->timeEntries()->count())->toBe(0)
        ->and($issue->comments()->count())->toBe(0);

    $page->assertNoSmoke();
});

it('creates and attaches a label on the fly', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Page de profil']);
    $this->actingAs($owner);

    $page = visit('/issues/'.$issue->id);

    $page->click('@label-add');
    $page->fill('@label-new-name', 'paiement-api');
    $page->click('@label-create-submit');

    // Creation then attachment are chained: wait for the round trips to settle.
    $page->waitForEvent('networkidle');
    $page->assertSee('paiement-api');

    app(WorkspaceContext::class)->set($workspace);
    $label = $workspace->labels()->where('name', 'paiement-api')->firstOrFail();
    expect($issue->labels()->where('id', $label->id)->exists())->toBeTrue();

    $page->assertNoSmoke();
});

it('edits the estimation from its dedicated sidebar dialog', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Page de profil']);
    $this->actingAs($owner);

    $page = visit('/issues/'.$issue->id);

    // Maquette: the Estimation prop is the only row without an action —
    // its dedicated dialog opens prefilled with the current value.
    $page->click('@estimate-dialog-open');
    $page->assertPresent('@estimate-dialog');
    $page->assertValue('@estimate-input', '');

    $page->fill('@estimate-input', '8')
        ->click('@estimate-submit');

    $page->waitForEvent('networkidle');
    $page->assertSee('8h');

    app(WorkspaceContext::class)->set($workspace);
    expect((float) $issue->fresh()->estimated_hours)->toBe(8.0);

    // The change is journalled: the Activity accordion keeps the trace.
    $page->click('[data-test="activity-accordion-trigger"]');
    $page->assertSeeIn('[data-test="activity-section"]', 'Estimate changed');

    $page->assertNoSmoke();
});

it('clears the estimation from the same dialog', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Page de profil']);
    SetIssueEstimate::run($issue, 4.0);
    $this->actingAs($owner);

    $page = visit('/issues/'.$issue->id);

    // The row shows the current estimate before the edit.
    $page->assertSee('4h');

    $page->click('@estimate-dialog-open');
    $page->assertValue('@estimate-input', '4');

    $page->fill('@estimate-input', '')
        ->click('@estimate-submit');

    $page->waitForEvent('networkidle');
    $page->assertSee('—');

    app(WorkspaceContext::class)->set($workspace);
    expect($issue->fresh()->estimated_hours)->toBeNull();

    $page->assertNoSmoke();
});

it('edits the due date and the estimation from the dates dialog', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Page de profil']);
    $this->actingAs($owner);

    $page = visit('/issues/'.$issue->id);

    $page->click('@dates-dialog-open');
    $page->fill('@dates-due', '2026-10-20');
    $page->fill('@dates-estimate', '6');
    $page->click('@dates-save');

    $page->waitForEvent('networkidle');
    $page->assertSee('6h');

    app(WorkspaceContext::class)->set($workspace);
    $fresh = $issue->fresh();
    expect($fresh->due_date->toDateString())->toBe('2026-10-20')
        ->and((float) $fresh->estimated_hours)->toBe(6.0);

    $page->assertNoSmoke();
});

it('adds and removes assignees independently from the sidebar', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    $member = User::factory()->create();
    Membership::create([
        'user_id' => $member->id,
        'workspace_id' => $workspace->id,
        'role' => 'member',
    ]);
    app(WorkspaceContext::class)->set($workspace);
    $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Page de profil']);
    $this->actingAs($owner);

    $page = visit('/issues/'.$issue->id);

    // Add the member through the ＋ menu of the Assignees prop.
    $page->click('@assignee-add');
    $page->click($member->name);
    $page->waitForEvent('networkidle');

    app(WorkspaceContext::class)->set($workspace);
    expect($issue->assignees()->where('user_id', $member->id)->exists())->toBeTrue();

    // The avatar lands in the stack and removes the assignee again.
    $page->click('button[aria-label="Remove '.$member->name.'"]');
    $page->waitForEvent('networkidle');
    $page->assertNotPresent('button[aria-label="Remove '.$member->name.'"]');

    app(WorkspaceContext::class)->set($workspace);
    expect($issue->assignees()->where('user_id', $member->id)->exists())->toBeFalse();

    $page->assertNoSmoke();
});

it('edits the title from the issue menu', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Page de profil']);
    $this->actingAs($owner);

    $page = visit('/issues/'.$issue->id);

    $page->click('@issue-menu');
    $page->click('Edit title…');
    $page->fill('#edit-issue-title', 'Nouveau titre');
    $page->click('@title-save');

    $page->waitForEvent('networkidle');
    $page->assertSee('Nouveau titre');

    app(WorkspaceContext::class)->set($workspace);
    expect($issue->fresh()->title)->toBe('Nouveau titre');

    $page->assertNoSmoke();
});

it('changes the priority from the sidebar', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Page de profil']);
    $this->actingAs($owner);

    $page = visit('/issues/'.$issue->id);

    $page->click('@priority-select');
    $page->click('[role="option"]:has-text("urgent")');

    $page->waitForEvent('networkidle');
    $page->assertSee('urgent');

    app(WorkspaceContext::class)->set($workspace);
    expect($issue->fresh()->priority->value)->toBe('urgent');

    $page->assertNoSmoke();
});

it('flags an overdue issue in the header and the sidebar', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $issue = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Page de profil',
        'due_date' => now()->subDays(3)->toDateString(),
    ]);
    $this->actingAs($owner);

    $page = visit('/issues/'.$issue->id);

    // Maquette: header badge "due … · overdue" + sidebar hint "Overdue for N days."
    $page->assertSee('· overdue');
    $page->assertSee('Overdue for');

    $page->assertNoSmoke();
});

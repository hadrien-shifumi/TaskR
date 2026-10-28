<?php

use App\Actions\Issues\CreateIssue;
use App\Enums\StatusCategory;
use App\Support\WorkspaceContext;

it('lists a project archive and unarchives from it', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $done = $workspace->statuses()->where('category', StatusCategory::Done->value)->firstOrFail();
    $issue = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Vestige archivable',
        'status_id' => $done->id,
    ]);
    $issue->update(['archived_at' => now()]);
    $this->actingAs($owner);

    // The board is clean; the archive keeps the issue readable.
    $board = visit("/projects/{$project->id}/board");
    $board->assertNotPresent("[data-test=\"board-card-{$issue->id}\"]");

    $page = visit("/projects/{$project->id}/archives");
    $page->assertSee('Vestige archivable');

    // Unarchiving (owner-only) brings the closed issue back to the board.
    $page->click("[data-test=\"archive-restore-{$issue->id}\"]");
    $page->assertSee('Issue restored.');

    expect($issue->fresh()->archived_at)->toBeNull();

    $page->assertNoSmoke();
});

it('filters the project archive by status and priority', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);

    $done = $workspace->statuses()->where('category', StatusCategory::Done->value)->firstOrFail();
    $cancelled = $workspace->statuses()->where('category', StatusCategory::Cancelled->value)->firstOrFail();

    // Three archived issues covering the status × priority matrix.
    $doneHigh = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Done high priority',
        'status_id' => $done->id,
        'priority' => 'high',
    ]);
    $doneHigh->update(['archived_at' => now()]);

    $doneLow = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Done low priority',
        'status_id' => $done->id,
        'priority' => 'low',
    ]);
    $doneLow->update(['archived_at' => now()]);

    $cancelledHigh = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Cancelled high priority',
        'status_id' => $cancelled->id,
        'priority' => 'high',
    ]);
    $cancelledHigh->update(['archived_at' => now()]);

    $this->actingAs($owner);

    $page = visit("/projects/{$project->id}/archives");

    // The full archive is listed initially.
    $page->assertSee('Done high priority')
        ->assertSee('Done low priority')
        ->assertSee('Cancelled high priority');

    // Priority = high keeps the two high-priority issues only.
    $page->click('@archives-filter-priority');
    $page->click('[role="option"]:has-text("high")');
    $page->assertQueryStringHas('priority', 'high');
    $page->assertSee('Done high priority')
        ->assertSee('Cancelled high priority')
        ->assertDontSee('Done low priority');

    // Filters cumulate (AND): high + Done keeps a single issue.
    $page->click('@archives-filter-status');
    $page->click('[role="option"]:has-text("Done")');
    $page->assertQueryStringHas('status', $done->id);
    $page->assertSee('Done high priority')
        ->assertDontSee('Cancelled high priority')
        ->assertDontSee('Done low priority');

    // Switching a filter replaces its value: Done + low keeps the low one.
    $page->click('@archives-filter-priority');
    $page->click('[role="option"]:has-text("low")');
    $page->assertQueryStringHas('priority', 'low');
    $page->assertSee('Done low priority')
        ->assertDontSee('Done high priority')
        ->assertDontSee('Cancelled high priority');

    // A combination matching nothing shows the filtered empty state.
    $page->click('@archives-filter-status');
    $page->click('[role="option"]:has-text("Cancelled")');
    $page->assertQueryStringHas('status', $cancelled->id);
    $page->assertVisible('@archives-empty');
    $page->assertSee('No archived issue matches these filters');

    // Clearing both filters restores the full archive.
    $page->click('@archives-filter-priority');
    $page->click('[role="option"]:has-text("All priorities")');
    $page->assertQueryStringMissing('priority');
    $page->click('@archives-filter-status');
    $page->click('[role="option"]:has-text("All statuses")');
    $page->assertQueryStringMissing('status');
    $page->assertSee('Done high priority')
        ->assertSee('Done low priority')
        ->assertSee('Cancelled high priority');

    $page->assertNoSmoke();
});

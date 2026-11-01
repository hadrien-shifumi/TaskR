<?php

use App\Actions\Comments\AddComment;
use App\Actions\Issues\CreateIssue;
use App\Actions\Issues\MoveIssue;
use App\Models\User;
use App\Support\WorkspaceContext;

it('shows the issue audit timeline newest first on the issue page', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $backlog = $workspace->statuses()->where('name', 'Backlog')->firstOrFail();
    $inProgress = $workspace->statuses()->where('name', 'In Progress')->firstOrFail();
    $issue = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Journal du ticket',
        'status_id' => $backlog->id,
    ], $owner);
    MoveIssue::run($issue, $inProgress->id, $owner);
    AddComment::run($issue, $owner, 'Vu en revue');
    $this->actingAs($owner);

    $page = visit('/issues/'.$issue->id);

    // The audit timeline lists the three journalled events with their i18n
    // type labels and the actor name.
    $page->assertSeeIn('[data-test="activity-section"]', 'Activity')
        ->assertSeeIn('[data-test="activity-section"]', 'Issue created')
        ->assertSeeIn('[data-test="activity-section"]', 'Status changed')
        ->assertSeeIn('[data-test="activity-section"]', 'Comment added');

    // Every row carries the actor: all three events were recorded by the
    // owner (strict mode forbids a duplicated section-wide text match).
    $actors = $page->script(<<<'JS'
        Array.from(document.querySelectorAll('[data-test="activity-event-actor"]')).map((el) => el.textContent.trim())
        JS);

    expect($actors)->toBe([$owner->name, $owner->name, $owner->name]);

    // Newest first: the comment sits on top, the creation at the bottom.
    $types = $page->script(<<<'JS'
        Array.from(
            document.querySelectorAll('[data-test="activity-event-row"] [data-test="activity-event-type"]'),
        ).map((el) => el.textContent.trim())
        JS);

    expect($types)->toBe(['Comment added', 'Status changed', 'Issue created']);

    // The status change carries its before → after pair.
    $page->assertSeeIn('[data-test="activity-section"]', 'Backlog → In Progress');

    // Every row exposes its date.
    $dates = $page->script(<<<'JS'
        Array.from(document.querySelectorAll('[data-test="activity-event-date"]')).map((el) => el.textContent.trim())
        JS);

    expect($dates)->toHaveCount(3)
        ->and($dates[0])->not->toBe('');

    $page->assertNoSmoke();
});

it('shows the audit timeline inside the board issue modal', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $backlog = $workspace->statuses()->where('name', 'Backlog')->firstOrFail();
    $inProgress = $workspace->statuses()->where('name', 'In Progress')->firstOrFail();
    $issue = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Journal en modal',
        'status_id' => $backlog->id,
    ]);
    MoveIssue::run($issue, $inProgress->id, $owner);
    $this->actingAs($owner);

    $page = visit("/projects/{$project->id}/board");
    $page->click("[data-test=\"board-card-{$issue->id}\"] a");

    // The modal renders the same audit timeline as the full fiche.
    $page->assertSeeIn('[data-test="issue-detail-dialog"] [data-test="activity-section"]', 'Issue created')
        ->assertSeeIn('[data-test="issue-detail-dialog"] [data-test="activity-section"]', 'Status changed');

    $rows = $page->script(
        'document.querySelectorAll(\'[data-test="issue-detail-dialog"] [data-test="activity-event-row"]\').length',
    );

    expect($rows)->toBe(2);

    $page->assertNoSmoke();
});

it('filters the project activity feed and aggregates status transitions', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $backlog = $workspace->statuses()->where('name', 'Backlog')->firstOrFail();
    $inProgress = $workspace->statuses()->where('name', 'In Progress')->firstOrFail();
    $issue = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Journal du projet',
        'status_id' => $backlog->id,
    ], $owner);
    MoveIssue::run($issue, $inProgress->id, $owner);
    $this->actingAs($owner);

    $page = visit("/projects/{$project->id}/activity");

    // The filter bar and the full event list are rendered.
    $page->assertPresent('[data-test="activity-filters"]')
        ->assertSeeIn('[data-test="activity-filters"]', 'All actors')
        ->assertSeeIn('[data-test="activity-filters"]', 'All types')
        ->assertSeeIn('[data-test="activity-section"]', 'Issue created')
        ->assertSeeIn('[data-test="activity-section"]', 'Status changed');

    // The transition aggregate counts the single Backlog → In Progress move.
    $page->assertSeeIn('[data-test="transitions-table"]', 'Backlog → In Progress');
    $count = $page->script(<<<'JS'
        (() => {
            const row = [...document.querySelectorAll('[data-test="transitions-table"] tbody tr')]
                .find((el) => el.textContent.includes('Backlog → In Progress'));
            return row ? row.lastElementChild.textContent.trim() : null;
        })()
        JS);

    expect($count)->toBe('1');

    // The type filter keeps only the matching events.
    $page = visit("/projects/{$project->id}/activity?type=issue.status_changed");
    $page->assertSeeIn('[data-test="activity-section"]', 'Status changed')
        ->assertDontSeeIn('[data-test="activity-section"]', 'Issue created');

    // The actor filter narrows to the given actor: an actor without events
    // empties the feed into the dedicated empty state…
    $bystander = User::factory()->create();
    $page = visit("/projects/{$project->id}/activity?actor_id={$bystander->id}");
    $page->assertPresent('[data-test="activity-empty"]')
        ->assertSeeIn('[data-test="activity-empty"]', 'No events yet.');

    // …while the owner's id brings the events back.
    $page = visit("/projects/{$project->id}/activity?actor_id={$owner->id}");
    $page->assertSeeIn('[data-test="activity-section"]', 'Issue created')
        ->assertSeeIn('[data-test="activity-section"]', 'Status changed');

    $page->assertNoSmoke();
});

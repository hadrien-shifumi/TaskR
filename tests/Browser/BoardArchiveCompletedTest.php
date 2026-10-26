<?php

use App\Actions\Issues\CreateIssue;
use App\Enums\StatusCategory;
use App\Support\WorkspaceContext;

it('shows the archive-completed confirmation with counts and flashes the result', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $done = $workspace->statuses()->where('category', StatusCategory::Done->value)->firstOrFail();
    $cancelled = $workspace->statuses()->where('category', StatusCategory::Cancelled->value)->firstOrFail();
    CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Terminée alpha',
        'status_id' => $done->id,
    ]);
    CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Terminée beta',
        'status_id' => $done->id,
    ]);
    CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Annulée gamma',
        'status_id' => $cancelled->id,
    ]);
    $this->actingAs($owner);

    $page = visit("/projects/{$project->id}/board");

    // The owner-facing button opens a confirmation showing both counters.
    $page->click('[data-test="board-archive-completed"]');
    $page->assertPresent('[data-test="archive-completed-dialog"]');
    $page->assertSeeIn('[data-test="archive-completed-counts"]', '2');
    $page->assertSeeIn('[data-test="archive-completed-counts"]', '1');

    // Confirming posts the mass archive; the flash reports the count.
    $page->click('[data-test="archive-completed-confirm"]');
    $page->assertNotPresent('[data-test="archive-completed-dialog"]');
    $page->assertSee('3 issues archived.');

    expect(
        $workspace->issues()
            ->whereNotNull('archived_at')
            ->count()
    )->toBe(3);

    $page->assertNoSmoke();
});

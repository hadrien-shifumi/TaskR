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

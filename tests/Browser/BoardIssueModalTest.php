<?php

use App\Actions\Issues\CreateIssue;
use App\Actions\Issues\SetIssueParent;
use App\Support\WorkspaceContext;

it('opens an issue in the board modal and closes back onto the board', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $backlog = $workspace->statuses()->where('name', 'Backlog')->firstOrFail();
    $issue = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Bouton retour absurde',
        'status_id' => $backlog->id,
    ]);
    $this->actingAs($owner);

    $page = visit("/projects/{$project->id}/board");

    // Clicking a card opens the fiche in a large modal — the user never
    // leaves the board.
    $page->click("[data-test=\"board-card-{$issue->id}\"] a");
    $page->assertSeeIn('[data-test="issue-detail-dialog"] h1', 'Bouton retour absurde');
    expect($page->script('location.pathname'))->toContain("/projects/{$project->id}/board");
    expect($page->script('location.search'))->toContain("issue={$issue->id}");

    // Closing the modal keeps the user on the board, modal-free.
    $page->click('[data-test="issue-detail-dialog"] [data-slot="dialog-close"]');
    $page->assertNotPresent('[data-test="issue-detail-dialog"]');
    expect($page->script('location.pathname'))->toContain("/projects/{$project->id}/board");
    expect($page->script('location.search'))->not->toContain('issue=');

    $page->assertNoSmoke();
});

it('copies the issue identifier from the board modal', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $backlog = $workspace->statuses()->where('name', 'Backlog')->firstOrFail();
    $issue = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Identifiant copiable',
        'status_id' => $backlog->id,
    ]);
    $this->actingAs($owner);

    $page = visit("/projects/{$project->id}/board");
    $page->click("[data-test=\"board-card-{$issue->id}\"] a");

    // The modal header mirrors the fiche pagebar: short mono identifier
    // with a copy action.
    $page->assertSeeIn('[data-test="issue-detail-dialog"]', substr($issue->id, 0, 7));

    $page->script('window.__copied = null');
    $page->script('navigator.clipboard.writeText = (text) => { window.__copied = text; return Promise.resolve(); }');
    $page->click('[data-test="issue-detail-dialog"] [data-test="copy-identifier"]');

    $page->assertSee('Copied');
    expect($page->script('window.__copied'))->toBe($issue->id);

    $page->assertNoSmoke();
});

it('navigates between child and parent issues inside the modal', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $backlog = $workspace->statuses()->where('name', 'Backlog')->firstOrFail();
    $parent = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Ticket parent',
        'status_id' => $backlog->id,
    ]);
    $child = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Sous-ticket enfant',
        'status_id' => $backlog->id,
    ]);
    SetIssueParent::run($child, $parent->id);
    $this->actingAs($owner);

    $page = visit("/projects/{$project->id}/board");

    // Open the parent fiche in the modal.
    $page->click("[data-test=\"board-card-{$parent->id}\"] a");
    $page->assertSeeIn('[data-test="issue-detail-dialog"] h1', 'Ticket parent');

    // The child link routes INSIDE the modal: the board stays put and the
    // issue param swaps. The h1 only shows the child title once its fiche
    // rendered in place of the parent's.
    $page->click('[data-test="issue-detail-dialog"] a:has-text("Sous-ticket enfant")');
    $page->assertSeeIn('[data-test="issue-detail-dialog"] h1', 'Sous-ticket enfant');
    expect($page->script('location.pathname'))->toContain("/projects/{$project->id}/board");
    expect($page->script('location.search'))->toContain("issue={$child->id}");

    // The parent link of the sidebar routes back inside the modal too.
    $page->click('[data-test="issue-detail-dialog"] a:has-text("Ticket parent")');
    $page->assertSeeIn('[data-test="issue-detail-dialog"] h1', 'Ticket parent');
    expect($page->script('location.search'))->toContain("issue={$parent->id}");

    $page->assertNoSmoke();
});

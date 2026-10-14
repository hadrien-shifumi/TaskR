<?php

use App\Actions\Issues\CreateIssue;
use App\Support\WorkspaceContext;

it('creates a project and a title-only issue from the UI', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    $this->actingAs($owner);

    $page = visit('/projects');

    $page->click('New project')
        ->fill('new-name', 'Refonte site vitrine')
        ->click('button[type="submit"]');

    $page->assertSee('Refonte site vitrine');

    $page->click('Issues')
        ->click('New issue')
        ->fill('issue-title', 'Page de profil')
        ->click('button[type="submit"]');

    $page->assertSee('Page de profil')
        ->assertSee('Backlog');

    // Scenario "Création minimale" (issue-tracking): title only falls back
    // to the workspace default status and the none priority.
    app(WorkspaceContext::class)->set($workspace);
    $issue = $workspace->issues()->where('title', 'Page de profil')->firstOrFail();

    expect($issue->status->name)->toBe('Backlog')
        ->and($issue->priority->value)->toBe('none');

    $page->assertNoSmoke();
});

it('moves an issue directly to done from the issue detail', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Page de profil']);
    $this->actingAs($owner);

    $page = visit('/issues/'.$issue->id);

    $page->assertSee('Page de profil');

    // Scenario "Passage direct à done" (issue-tracking): no transition constraints.
    $page->click('@status-select')
        ->click('Done');

    $page->assertSee('Done');

    app(WorkspaceContext::class)->set($workspace);
    $issue->refresh();

    expect($issue->status->name)->toBe('Done')
        ->and($issue->status->category->value)->toBe('done');

    $page->assertNoSmoke();
});

it('manages comments on an issue from the UI', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Page de profil']);
    $this->actingAs($owner);

    $page = visit('/issues/'.$issue->id);

    // Add a comment as the author.
    $page->fill('textarea[placeholder="Add a comment..."]', 'Premier commentaire')
        ->click('@comment-submit');

    $page->assertSee('Premier commentaire');

    // Edit it.
    $page->click('@comment-edit')
        ->fill('@comment-edit-body', 'Commentaire corrigé')
        ->click('@comment-edit-save');

    $page->assertSee('Commentaire corrigé')
        ->assertDontSee('Premier commentaire');

    // Delete it: the plugin auto-dismisses native confirm dialogs, so accept them.
    $page->script('window.confirm = () => true');
    $page->click('@comment-delete');

    $page->assertDontSee('Commentaire corrigé')
        ->assertSee('Comments (0)');

    app(WorkspaceContext::class)->set($workspace);
    expect($issue->comments()->count())->toBe(0);

    $page->assertNoSmoke();
});

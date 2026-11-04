<?php

use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Models\Membership;
use App\Models\ProjectMember;
use App\Models\Status;
use App\Models\User;
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

    // The comments accordion folds by default: open it to reach the box.
    $page->click('[data-test="comments-accordion-trigger"]');

    // Add a comment as the author.
    $page->fill('textarea[placeholder="Add a comment..."]', 'Premier commentaire')
        ->click('@comment-submit');

    $page->assertSee('Premier commentaire');

    // Edit it.
    $page->click('@comment-edit')
        ->fill('@comment-edit-body', 'Commentaire corrigé')
        ->click('@comment-edit-save');

    // The old body must leave the comments LIST, not the audit timeline:
    // the activity section keeps the before → after trace of the edit
    // (audit spec), so the dont-see assertions are scoped to the comments
    // section, which excludes [data-test="activity-section"].
    $commentsSection = 'section:has([data-test="comment-submit"])';

    $page->assertSee('Commentaire corrigé')
        ->assertDontSeeIn($commentsSection, 'Premier commentaire');

    // Delete it: the plugin auto-dismisses native confirm dialogs, so accept them.
    $page->script('window.confirm = () => true');
    $page->click('@comment-delete');

    $page->assertDontSeeIn($commentsSection, 'Commentaire corrigé')
        ->assertSee('Comments (0)');

    app(WorkspaceContext::class)->set($workspace);
    expect($issue->comments()->count())->toBe(0);

    $page->assertNoSmoke();
});

it('creates a title-only issue from the home quick dialog', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $this->actingAs($owner);

    $page = visit('/');

    // Maquette home: the pagebar "New issue" opens the minimal dialog (title
    // + project only); status and priority fall back to workspace defaults.
    $page->click('New issue')
        ->assertSee('stay optional and editable after creation')
        ->fill('quick-issue-title', 'Alerte depuis home')
        ->click('[data-slot="select-trigger"]')
        ->click('[role="option"]:has-text("Site vitrine")')
        ->click('button[type="submit"]');

    app(WorkspaceContext::class)->set($workspace);
    $issue = $workspace->issues()->where('title', 'Alerte depuis home')->firstOrFail();

    expect($issue->project_id)->toBe($project->id)
        ->and($issue->status->category->value)->toBe('backlog')
        ->and($issue->priority->value)->toBe('none');

    $page->assertSee('Alerte depuis home')
        ->assertNoSmoke();
});

it('requires a title in the home quick dialog', function () {
    [$owner, $workspace] = setupBrowserWorkspace(withProject: true);
    $this->actingAs($owner);

    $page = visit('/');

    $page->click('New issue')
        ->click('button[type="submit"]');

    $page->assertSee('The title field is required');

    app(WorkspaceContext::class)->set($workspace);
    expect($workspace->issues()->count())->toBe(0);

    $page->assertNoSmoke();
});

it('scopes the guest home dashboard to their perimeter', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);

    $inScope = CreateProject::run($workspace, $owner, ['name' => 'Projet client']);
    $outOfScope = CreateProject::run($workspace, $owner, ['name' => 'Interne secret']);

    $backlog = Status::withoutWorkspaceScope()
        ->where('workspace_id', $workspace->id)
        ->where('category', 'backlog')
        ->firstOrFail();

    CreateIssue::run($workspace, [
        'project_id' => $inScope->id,
        'title' => 'Livraison client',
        'status_id' => $backlog->id,
    ]);
    $residual = CreateIssue::run($workspace, [
        'project_id' => $outOfScope->id,
        'title' => 'Ticket confidentiel',
        'status_id' => $backlog->id,
    ]);

    $guest = User::factory()->create();
    Membership::create([
        'user_id' => $guest->id,
        'workspace_id' => $workspace->id,
        'role' => 'guest',
    ]);
    ProjectMember::create([
        'project_id' => $inScope->id,
        'user_id' => $guest->id,
        'role' => 'member',
    ]);
    $residual->assignees()->attach($guest);

    $this->actingAs($guest);

    $page = visit('/');

    // Cartes de santé et compteurs : uniquement le périmètre du guest.
    $page->assertSee('Projet client')
        ->assertDontSee('Interne secret')
        // Assignation résiduelle hors périmètre : jamais exposée.
        ->assertDontSee('Ticket confidentiel')
        ->assertNoSmoke();
});

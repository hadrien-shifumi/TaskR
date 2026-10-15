<?php

use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Models\Project;
use App\Models\Status;
use App\Models\Workspace;
use App\Support\WorkspaceContext;

/**
 * Projects-list fixture (maquette screens/projects.html): an active project
 * "Refonte site vitrine" (description, overdue target date, 4 issues with
 * 2 done → 50% complete, late) and an archived project "Sprint zéro"
 * (2 issues, archived yesterday).
 *
 * @return array{0: Project, 1: Project}
 */
function seedProjectsListProjects(Workspace $workspace): array
{
    app(WorkspaceContext::class)->set($workspace);

    $active = CreateProject::run($workspace, [
        'name' => 'Refonte site vitrine',
        'description' => 'Nouvelle identité, pages éditoriales et SEO.',
        'target_date' => now()->subDay()->toDateString(),
    ]);

    $statuses = Status::withoutWorkspaceScope()
        ->where('workspace_id', $workspace->id)
        ->get()
        ->keyBy(fn (Status $status) => $status->category->value);

    CreateIssue::run($workspace, ['project_id' => $active->id, 'title' => 'D1', 'status_id' => $statuses['done']->id]);
    CreateIssue::run($workspace, ['project_id' => $active->id, 'title' => 'D2', 'status_id' => $statuses['done']->id]);
    CreateIssue::run($workspace, ['project_id' => $active->id, 'title' => 'B1']);
    CreateIssue::run($workspace, ['project_id' => $active->id, 'title' => 'B2']);

    $archived = CreateProject::run($workspace, ['name' => 'Sprint zéro']);
    CreateIssue::run($workspace, ['project_id' => $archived->id, 'title' => 'A1']);
    CreateIssue::run($workspace, ['project_id' => $archived->id, 'title' => 'A2']);
    $archived->update(['archived_at' => now()->subDay()]);

    return [$active, $archived];
}

it('renders the active projects table like the maquette', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    seedProjectsListProjects($workspace);
    $this->actingAs($owner);

    $page = visit('/projects');

    // Maquette columns: Projet, Santé, Terminé, Date cible, Issues, actions.
    $page->assertSee('Project')
        ->assertSee('Health')
        ->assertSee('Completed')
        ->assertSee('Target date')
        ->assertSee('Issues');

    // Row: project name + description sub-line.
    $page->assertSee('Refonte site vitrine')
        ->assertSee('Nouvelle identité, pages éditoriales et SEO.');

    // Computed health: late badge, 50% completion, 4 issues in the row
    // (columns 3 and 5 of the maquette table).
    $page->assertSee('Late')
        ->assertSeeIn('tr:has-text("Refonte site vitrine") td:nth-child(3)', '50%')
        ->assertSeeIn('tr:has-text("Refonte site vitrine") td:nth-child(5)', '4');

    // The pagebar carries the archived counter.
    $page->assertSeeIn('@open-archived-dialog', '1');

    // Archived projects stay out of the active table.
    $page->assertDontSee('Sprint zéro');

    // Row actions menu (maquette: Modifier… / Archiver… / Supprimer…).
    $page->click('button[aria-label="Actions for Refonte site vitrine"]');
    $page->assertSee('Edit…')
        ->assertSee('Archive…')
        ->assertSee('Delete…');

    $page->assertNoSmoke();
});

it('lists archived projects in a dialog with issue count, archive date and actions', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    seedProjectsListProjects($workspace);
    $this->actingAs($owner);

    $page = visit('/projects');

    $page->click('@open-archived-dialog');

    // Maquette dlg-archived: one row per archived project, meta line
    // "N issues · archivé le …", actions Consulter / Désarchiver.
    $page->assertSee('Archived projects')
        ->assertSee('Sprint zéro')
        ->assertSee('2 issues')
        ->assertSee('archived on')
        ->assertSee('View')
        ->assertSee('Unarchive');

    // The active table stays behind the dialog.
    $page->assertSee('Refonte site vitrine');

    $page->assertNoSmoke();
});

it('restores an archived project from the archived dialog', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    [$active, $archived] = seedProjectsListProjects($workspace);
    $this->actingAs($owner);

    $page = visit('/projects');

    $page->click('@open-archived-dialog')
        ->click('Unarchive');

    // Back in the active table; the archived counter disappears.
    $page->assertSee('Sprint zéro')
        ->assertMissing('@open-archived-dialog');

    app(WorkspaceContext::class)->set($workspace);
    expect($archived->fresh()->archived_at)->toBeNull();

    $page->assertNoSmoke();
});

it('opens an archived project from the archived dialog', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    [$active, $archived] = seedProjectsListProjects($workspace);
    $this->actingAs($owner);

    $page = visit('/projects');

    $page->click('@open-archived-dialog')
        ->click('View');

    $page->assertPathIs("/projects/{$archived->id}/health")
        ->assertSee('Sprint zéro');

    $page->assertNoSmoke();
});

it('asks for confirmation before archiving a project from the row menu', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    [$active, $archived] = seedProjectsListProjects($workspace);
    $this->actingAs($owner);

    $page = visit('/projects');

    $page->click('button[aria-label="Actions for Refonte site vitrine"]')
        ->click('Archive…');

    // Maquette dlg-archive: confirmation dialog with an info banner.
    $page->assertSee('Archive "Refonte site vitrine"?')
        ->assertSee('No data is deleted');

    $page->click('Archive');

    // The project leaves the active table and the flash confirms it.
    $page->assertSee('No active projects yet')
        ->assertSee('Project "Refonte site vitrine" archived.');

    app(WorkspaceContext::class)->set($workspace);
    expect($active->fresh()->isArchived())->toBeTrue();

    $page->assertNoSmoke();
});

it('refuses to delete a project that still has issues and offers archiving instead', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    [$active, $archived] = seedProjectsListProjects($workspace);
    $this->actingAs($owner);

    $page = visit('/projects');

    $page->click('button[aria-label="Actions for Refonte site vitrine"]')
        ->click('Delete…');

    // Deletion is attempted, refused (409), and the archive path offered.
    $page->assertSee('Delete "Refonte site vitrine"?')
        ->assertSee('This project contains issues');

    $page->click('@confirm-delete-project');

    $page->assertSee('Deletion refused');

    $page->click('@archive-instead');

    $page->assertSee('No active projects yet')
        ->assertSee('Project "Refonte site vitrine" archived.');

    app(WorkspaceContext::class)->set($workspace);
    expect($active->fresh()->isArchived())->toBeTrue();

    $page->assertNoSmoke();
});

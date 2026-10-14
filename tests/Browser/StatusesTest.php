<?php

use App\Actions\Issues\CreateIssue;
use App\Models\Membership;
use App\Models\Status;
use App\Models\User;
use App\Support\WorkspaceContext;

it('lets an owner create, rename and reorder statuses from the UI', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    $this->actingAs($owner);

    $page = visit('/settings/statuses');

    // Scenario "Création d'un statut" (status-management).
    $page->click('New status');
    $page->fill('new-name', 'En revue');
    $page->click('[role="combobox"]');
    $page->click('[role="option"]:has-text("In Progress")');
    $page->click('button[type="submit"]');

    $page->assertSee('En revue');

    // Rename it.
    $page->click('tr:has-text("En revue") button:has-text("Edit")');
    $page->fill('edit-name', 'En relecture');
    $page->click('button[type="submit"]');

    $page->assertSee('En relecture');

    // Scenario "Réordonnancement des statuts": move it above Cancelled.
    $page->click('tr:has-text("En relecture") button:has-text("Up")');

    $page->assertSeeIn('tbody tr:nth-child(4)', 'En relecture');

    app(WorkspaceContext::class)->set($workspace);
    $names = Status::withoutWorkspaceScope()
        ->where('workspace_id', $workspace->id)
        ->orderBy('position')
        ->pluck('name')
        ->all();

    expect($names)->toBe(['Backlog', 'In Progress', 'Done', 'En relecture', 'Cancelled']);

    $page->assertNoSmoke();
});

it('refuses to delete a status that issues still use', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $status = $workspace->statuses()->where('name', 'In Progress')->firstOrFail();
    CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Page de profil', 'status_id' => $status->id]);
    $this->actingAs($owner);

    $page = visit('/settings/statuses');

    $page->script('window.confirm = () => true');
    $page->click('tr:has-text("In Progress") button:has-text("Delete")');

    // Scenario "Suppression refusée" (status-management): the deletion is
    // refused while issues use the status. Note: the 409 rejection is
    // currently silent in the UI — the server message never surfaces to
    // the user (surfaced to the status-management change, see tasks.md 5.3).
    $page->assertSee('In Progress');

    app(WorkspaceContext::class)->set($workspace);
    expect(
        Status::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->where('name', 'In Progress')->exists()
    )->toBeTrue();
});

it('refuses a status creation attempted by a member', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    $member = User::factory()->create();
    Membership::create([
        'user_id' => $member->id,
        'workspace_id' => $workspace->id,
        'role' => 'member',
    ]);
    $this->actingAs($member);

    $page = visit('/settings/statuses');

    $page->click('New status');
    $page->fill('new-name', 'Backdoor');
    $page->click('button[type="submit"]');

    // Scenario "Tentative par un member": nothing is created.
    app(WorkspaceContext::class)->set($workspace);
    expect(
        Status::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->count()
    )->toBe(4);
});

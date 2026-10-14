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
    $page->click('[data-test="status-row-En revue"] button:has-text("Edit")');
    $page->fill('edit-name', 'En relecture');
    $page->click('button[type="submit"]');

    $page->assertSee('En relecture');

    // Scenario "Réordonnancement des statuts": move it above Cancelled.
    $page->click('[data-test="status-row-En relecture"] button:has-text("Up")');

    $page->assertSeeIn('[data-test^="status-row-"]:nth-child(4)', 'En relecture');

    app(WorkspaceContext::class)->set($workspace);
    $names = Status::withoutWorkspaceScope()
        ->where('workspace_id', $workspace->id)
        ->orderBy('position')
        ->pluck('name')
        ->all();

    expect($names)->toBe(['Backlog', 'In Progress', 'Done', 'En relecture', 'Cancelled']);

    $page->assertNoSmoke();
});

it('refuses to delete a status that issues still use until they are migrated', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $status = $workspace->statuses()->where('name', 'In Progress')->firstOrFail();
    CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Page de profil', 'status_id' => $status->id]);
    $this->actingAs($owner);

    $page = visit('/settings/statuses');

    // The delete dialog opens and demands a migration target (protected deletion).
    $page->click('[data-test="status-row-In Progress"] button:has-text("Delete")');
    $page->assertSee('used by 1 issue');

    // Migrate the issue to Done, then delete: both happen through the dialog.
    $page->click('[role="combobox"]');
    $page->click('[role="option"]:has-text("Done")');
    $page->click('[data-test="confirm-delete-status"]');

    $page->assertSee('Status deleted');

    app(WorkspaceContext::class)->set($workspace);
    expect(
        Status::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->where('name', 'In Progress')->exists()
    )->toBeFalse();
    expect(
        \App\Models\Issue::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->where('status_id', $status->id)->exists()
    )->toBeFalse();
});

it('shows settings read-only to a member', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    $member = User::factory()->create();
    Membership::create([
        'user_id' => $member->id,
        'workspace_id' => $workspace->id,
        'role' => 'member',
    ]);
    $this->actingAs($member);

    $page = visit('/settings/statuses');

    // Scenario "Tentative par un member": owner-only controls are not rendered.
    $page->assertSee('Read-only — member');
    $page->assertMissing('button:has-text("New status")');
    $page->assertMissing('button:has-text("Delete")');

    app(WorkspaceContext::class)->set($workspace);
    expect(
        Status::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->count()
    )->toBe(4);
});

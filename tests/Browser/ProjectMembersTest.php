<?php

use App\Models\Membership;
use App\Models\ProjectMember;
use App\Models\User;
use App\Support\WorkspaceContext;

it('lets the workspace owner onboard a guest onto the project roster', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);

    $guest = User::factory()->create(['name' => 'Alice Client', 'email' => 'alice@example.com']);
    Membership::create([
        'user_id' => $guest->id,
        'workspace_id' => $workspace->id,
        'role' => 'guest',
    ]);

    $this->actingAs($owner);

    $page = visit("/projects/{$project->id}/members");

    // Scenario "Pilotage du périmètre d'un client": the owner opens the
    // onboarding dialog and picks the guest — their access scope grows.
    $page->assertSee('Project members')
        ->click('Add member')
        ->click('#member-select')
        ->click('[role="option"]:has-text("Alice Client")')
        ->click('button[type="submit"]');

    // The roster shows the guest, whose scope now includes the project.
    $page->assertSee('Alice Client');

    app(WorkspaceContext::class)->set($workspace);
    expect(ProjectMember::withoutWorkspaceScope()
        ->where('workspace_id', $workspace->id)
        ->where('project_id', $project->id)
        ->where('user_id', $guest->id)
        ->exists())->toBeTrue();

    $page->assertNoSmoke();
});

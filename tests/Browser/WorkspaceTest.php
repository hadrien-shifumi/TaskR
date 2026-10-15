<?php

use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Invitation;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Support\Str;

it('lets an invited user join the workspace through the acceptance link', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Page de profil']);

    // There is no invitation UI yet: the owner's invite is the domain's
    // (InviteMember), the browser covers the acceptance journey.
    $invitation = Invitation::withoutWorkspaceScope()->create([
        'workspace_id' => $workspace->id,
        'email' => 'bob@example.com',
        'token' => Str::random(64),
        'expires_at' => now()->addDays(7),
    ]);

    $bob = User::factory()->create(['email' => 'bob@example.com']);
    $this->actingAs($bob);

    // Scenario "Invitation acceptée" (workspace-management). The route key
    // is the invitation token — the unguessable identifier from the link.
    $page = visit('/invitations/'.$invitation->token.'/accept');

    $page->assertPathIs('/')
        ->assertSee('WS');

    // Bob sees the workspace's projects and issues.
    $page->click('Projects')
        ->assertSee('Site vitrine');

    $page->click('Issues')
        ->assertSee('Page de profil');

    app(WorkspaceContext::class)->set($workspace);
    expect($workspace->hasMember($bob))->toBeTrue()
        ->and($workspace->roleOf($bob)->value)->toBe('member');
});

it('switches the active workspace and only shows its data', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);
    CreateProject::run($workspace, ['name' => 'Projet A']);

    $second = CreateWorkspace::run($owner, ['name' => 'WS 2']);
    app(WorkspaceContext::class)->set($second);
    CreateProject::run($second, ['name' => 'Projet B']);
    $this->actingAs($owner);

    $page = visit('/projects');

    // Scenario "Changement de workspace actif": the first workspace by name
    // is active, and only its projects are listed.
    $page->assertSee('Projet A')
        ->assertDontSee('Projet B');

    // The switch lands on home (SwitchWorkspace redirects to the workspace root).
    $page->click('WS')
        ->click('WS 2');

    $page->assertPathIs('/');

    $page->click('Projects')
        ->assertSee('Projet B')
        ->assertDontSee('Projet A');

    $page->assertNoSmoke();
});

it('creates a workspace from the workspace switcher', function () {
    [$owner] = setupBrowserWorkspace();
    $this->actingAs($owner);

    $page = visit('/projects');

    // The switcher menu exposes "Create workspace…": the dialog submits to
    // workspaces.store, which activates the new workspace and lands on home.
    $page->click('WS')
        ->click('Create workspace…')
        ->fill('new-workspace-name', 'Nouveau workspace')
        ->click('button[type="submit"]');

    $page->assertPathIs('/')
        ->assertSee('Nouveau workspace');

    expect(Workspace::where('name', 'Nouveau workspace')->exists())->toBeTrue();

    $page->assertNoSmoke();
});

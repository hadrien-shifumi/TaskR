<?php

use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\InviteMember;
use App\Enums\WorkspaceRole;
use App\Models\Invitation;
use App\Models\InvitationProject;
use App\Models\Membership;
use App\Models\User;
use App\Support\WorkspaceContext;

it('lets an owner invite a guest with a project scope from the members settings', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);
    $project = CreateProject::run($workspace, $owner, ['name' => 'Projet client']);
    $this->actingAs($owner);

    $page = visit('/settings/members');

    // Scenario "Inviter un guest rattache a un projet": role select, project
    // chip toggle, then submit. The pending row shows the guest role and the
    // announced scope.
    $page->click('button:has-text("Invite")')
        ->fill('invite-email', 'client@example.com')
        ->click('#invite-role')
        ->click('[role="option"]:has-text("guest")')
        ->click('button:has-text("Projet client")')
        ->click('button[type="submit"]');

    app(WorkspaceContext::class)->set($workspace);
    $dbg = Invitation::withoutWorkspaceScope()
        ->where('workspace_id', $workspace->id)
        ->where('email', 'client@example.com')
        ->first();
    $page->assertSee('Invitation created for client@example.com');

    var_dump('FETCHED PROPS >>>', substr((string) $page->script(
        '() => fetch("/settings/members", { headers: { "X-Inertia": "true", "X-Inertia-Version": "" }, credentials: "same-origin" }).then(function (r) { return r.text(); })'
    ), 0, 1100));

    $page->navigate('/settings/members');
    $page->assertSee('client@example.com');

    var_dump('LOADED RESOURCES >>>', (string) $page->script(
        '() => performance.getEntriesByType("resource").map(function (r) { return r.name; }).filter(function (n) { return n.indexOf("assets") !== -1; }).join(" || ")'
    ));

    $page->assertSee('Projet client');

    app(WorkspaceContext::class)->set($workspace);
    $invitation = Invitation::withoutWorkspaceScope()
        ->where('workspace_id', $workspace->id)
        ->where('email', 'client@example.com')
        ->firstOrFail();

    expect($invitation->role)->toBe(WorkspaceRole::Guest)
        ->and(InvitationProject::query()->where('invitation_id', $invitation->id)->pluck('project_id')->all())
        ->toBe([$project->id]);

    $page->assertNoSmoke();
});

it('lets an owner resend and cancel a pending invitation from the members settings', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    $invitation = InviteMember::run($workspace, ['email' => 'bob@example.com']);
    $oldToken = $invitation->token;
    $this->actingAs($owner);

    $page = visit('/settings/members');

    // The pending invitation is listed with its owner actions.
    $page->assertSee('bob@example.com')
        ->assertSee('Invitation sent');

    // Scenario "Renvoyer l'invitation": the token is regenerated and the
    // fresh acceptance link is exposed through the flash.
    $page->click('[data-test="invitation-row-bob@example.com"] button[aria-label="Actions for invitation bob@example.com"]')
        ->click('Resend invitation')
        ->assertSee('Invitation resent for bob@example.com');

    app(WorkspaceContext::class)->set($workspace);
    $fresh = Invitation::withoutWorkspaceScope()->findOrFail($invitation->id);
    expect($fresh->token)->not->toBe($oldToken);

    // Scenario "Annuler l'invitation…": the invitation leaves the page
    // and the database. The plugin auto-dismisses native confirm dialogs,
    // so accept them.
    $page->script('window.confirm = () => true');
    $page->click('[data-test="invitation-row-bob@example.com"] button[aria-label="Actions for invitation bob@example.com"]')
        ->click('Cancel invitation…')
        ->assertDontSee('Invitation sent');

    app(WorkspaceContext::class)->set($workspace);
    expect(Invitation::withoutWorkspaceScope()->where('id', $invitation->id)->exists())->toBeFalse();

    $page->assertNoSmoke();
});

it('lets an owner invite a member with a project perimeter from the members settings', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);
    $client = CreateProject::run($workspace, $owner, ['name' => 'Projet client']);
    $internal = CreateProject::run($workspace, $owner, ['name' => 'Interne secret']);
    $this->actingAs($owner);

    $page = visit('/settings/members');

    // Scenario "Inviter un membre restreint a un perimetre": member role
    // keeps the project chips — the default (no chip) stays full access.
    $page->click('button:has-text("Invite")')
        ->fill('invite-email', 'peer@example.com')
        ->click('button:has-text("Projet client")')
        ->click('button[type="submit"]');

    app(WorkspaceContext::class)->set($workspace);
    $invitation = Invitation::withoutWorkspaceScope()
        ->where('workspace_id', $workspace->id)
        ->where('email', 'peer@example.com')
        ->firstOrFail();

    expect($invitation->role)->toBe(WorkspaceRole::Member)
        ->and($invitation->projects()->pluck('projects.id')->all())->toBe([$client->id]);

    $page->assertNoSmoke();
});

it('lets an owner edit a member perimeter from the members settings', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);
    $client = CreateProject::run($workspace, $owner, ['name' => 'Projet client']);
    $internal = CreateProject::run($workspace, $owner, ['name' => 'Interne secret']);

    $member = User::factory()->create(['name' => 'Robin']);
    $membership = Membership::create([
        'user_id' => $member->id,
        'workspace_id' => $workspace->id,
        'role' => 'member',
    ]);
    $membership->update(['is_scoped' => true]);
    $membership->syncProjectScope([$client->id]);

    $this->actingAs($owner);

    $page = visit('/settings/members');

    // The scoped member's row announces the current perimeter.
    $page->assertSee('Projet client');

    // Scenario "Modifier le perimetre": the row menu opens the editor,
    // preselected on the current perimeter; adding the internal project
    // extends it, saving persists the new list.
    $page->click('button[aria-label="Actions for Robin"]')
        ->click('Edit perimeter…')
        ->click('button:has-text("Interne secret")')
        ->click('button[type="submit"]');

    app(WorkspaceContext::class)->set($workspace);
    $membership->refresh();
    expect($membership->is_scoped)->toBeTrue()
        ->and($membership->projectScopeIds())->toBe([$client->id, $internal->id]);

    $page->assertNoSmoke();
});

it('shows the members settings read-only to a member', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    InviteMember::run($workspace, ['email' => 'bob@example.com']);
    $member = User::factory()->create();
    Membership::create([
        'user_id' => $member->id,
        'workspace_id' => $workspace->id,
        'role' => 'member',
    ]);
    $this->actingAs($member);

    $page = visit('/settings/members');

    // Scenario "Tentative par un member": the invitation is visible but no
    // owner action is rendered, and the read-only banner explains why.
    $page->assertSee('bob@example.com')
        ->assertSee('Read-only — member')
        ->assertMissing('button:has-text("Invite")')
        ->assertMissing('[data-test="invitation-row-bob@example.com"] button[aria-label="Actions for invitation bob@example.com"]');

    $page->assertNoSmoke();
});

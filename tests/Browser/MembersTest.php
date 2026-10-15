<?php

use App\Actions\Workspaces\InviteMember;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\User;
use App\Support\WorkspaceContext;

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

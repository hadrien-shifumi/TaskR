<?php

use App\Models\Invitation;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Support\Str;

it('registers a user and provisions their workspace with the four default statuses', function () {
    $page = visit('/register');

    $page->fill('name', 'Alice')
        ->fill('email', 'alice@example.com')
        ->fill('password', 'password')
        ->fill('password_confirmation', 'password')
        ->fill('workspace_name', 'Studio')
        ->press('Register');

    $page->assertPathIs('/')
        ->assertSee('Studio')
        ->assertNoSmoke();

    expect(User::where('email', 'alice@example.com')->exists())->toBeTrue();

    $workspace = Workspace::query()->where('name', 'Studio')->firstOrFail();
    app(WorkspaceContext::class)->set($workspace);

    expect($workspace->statuses->pluck('name')->all())
        ->toBe(['Backlog', 'In Progress', 'Done', 'Cancelled'])
        ->and($workspace->statuses->map(fn ($status) => $status->category->value)->all())
        ->toBe(['backlog', 'in_progress', 'done', 'cancelled']);
});

it('logs a user in and out through the form', function () {
    [$owner] = setupBrowserWorkspace();
    $owner->update(['name' => 'Alice']);

    $page = visit('/login');

    $page->fill('email', $owner->email)
        ->fill('password', 'password')
        ->click('button[type="submit"]');

    $page->assertPathIs('/')
        ->assertSee('WS');

    $page->click('Alice')
        ->click('Log out');

    $page->assertPathIs('/login');

    $page->fill('email', $owner->email)
        ->fill('password', 'password')
        ->click('button[type="submit"]');

    $page->assertPathIs('/')
        ->assertSee('WS')
        ->assertNoSmoke();
});

it('accepts an invitation through the landing page and login', function () {
    [, $workspace] = setupBrowserWorkspace();
    $newcomer = User::factory()->create(['email' => 'newcomer@example.com']);
    $invitation = Invitation::withoutWorkspaceScope()->create([
        'workspace_id' => $workspace->id,
        'email' => 'newcomer@example.com',
        'token' => Str::random(64),
        'expires_at' => now()->addDays(7),
    ]);

    $page = visit("/invitations/{$invitation->token}/accept");

    $page->assertSee('Accept the invitation')
        ->assertValue('#invitation-email', 'newcomer@example.com')
        ->click('Accept the invitation');

    $page->assertPathIs('/login')
        ->fill('email', 'newcomer@example.com')
        ->fill('password', 'password')
        ->click('button[type="submit"]');

    $page->assertPathIs('/')
        ->assertSee('WS')
        ->assertNoSmoke();

    app(WorkspaceContext::class)->set($workspace);

    expect($newcomer->memberships()->where('workspace_id', $workspace->id)->exists())->toBeTrue()
        ->and($invitation->fresh()->accepted_at)->not->toBeNull();
});

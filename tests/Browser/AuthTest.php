<?php

use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;

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

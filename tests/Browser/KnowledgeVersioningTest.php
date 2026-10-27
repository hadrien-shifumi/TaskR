<?php

use App\Actions\Knowledge\CreateKnowledgePage;
use App\Actions\Knowledge\UpdateKnowledgePage;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\Queue;

it('views the version history of a page', function () {
    Queue::fake();
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);

    $page = CreateKnowledgePage::run($workspace, $owner, [
        'title' => 'V1',
        'body' => 'Body V1.',
    ]);
    UpdateKnowledgePage::run($page, $owner, [
        'title' => 'V2',
        'body' => 'Body V2.',
    ]);

    $this->actingAs($owner);

    // Open the page, then click the History button.
    $browser = visit('/knowledge/'.$page->id);
    $browser->assertSee('V2');

    $browser->click('button:has-text("History")');

    // On the versions page: both versions are listed, most recent first.
    $browser->assertPathIs('/knowledge/'.$page->id.'/versions');
    $browser->assertSee('Version history');
    $browser->assertSee('V2');
    $browser->assertSee('V1');

    $browser->assertNoSmoke();
});

it('restores a previous version from the history', function () {
    Queue::fake();
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);

    $page = CreateKnowledgePage::run($workspace, $owner, [
        'title' => 'V1',
        'body' => 'Body V1.',
    ]);
    UpdateKnowledgePage::run($page, $owner, [
        'title' => 'V2',
        'body' => 'Body V2.',
    ]);

    $this->actingAs($owner);

    // Go to the versions page.
    $browser = visit('/knowledge/'.$page->id.'/versions');
    $browser->assertSee('V2');
    $browser->assertSee('V1');

    // Versions are ordered most-recent-first: the second row is V1.
    // Each version row has a single Restore button — target the second
    // row's button directly via its container position.
    $browser->click('div.space-y-2 > div:nth-child(2) button:has-text("Restore")');
    $browser->assertPresent('[data-test="confirm-restore-version"]');
    $browser->click('[data-test="confirm-restore-version"]');

    // Redirected to the page — content reverted to V1.
    $browser->assertPathIs('/knowledge/'.$page->id);
    $browser->assertSee('V1');
    $browser->assertSee('Body V1.');

    // The page content in the database matches V1.
    app(WorkspaceContext::class)->set($workspace);
    $page->refresh();
    expect($page->title)->toBe('V1');
    expect($page->body)->toBe('Body V1.');

    $browser->assertNoSmoke();
});

it('shows the empty state when a page has no versions', function () {
    Queue::fake();
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);

    $page = CreateKnowledgePage::run($workspace, $owner, [
        'title' => 'Page without versions',
        'body' => 'Body.',
    ]);

    // Simulate a page with no versions (edge case: versions deleted manually).
    $page->versions()->delete();

    $this->actingAs($owner);

    $browser = visit('/knowledge/'.$page->id.'/versions');
    $browser->assertSee('No versions yet.');

    $browser->assertNoSmoke();
});

it('shows the editor name on version entries', function () {
    Queue::fake();
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);

    $page = CreateKnowledgePage::run($workspace, $owner, [
        'title' => 'Page',
        'body' => 'Body.',
    ]);

    $this->actingAs($owner);

    $browser = visit('/knowledge/'.$page->id.'/versions');
    $browser->assertSee($owner->name);
    $browser->assertSee('Edited by');

    $browser->assertNoSmoke();
});

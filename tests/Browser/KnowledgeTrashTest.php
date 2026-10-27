<?php

use App\Actions\Knowledge\CreateKnowledgePage;
use App\Models\KnowledgePage;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\Queue;

it('deletes a page and sees it in the trash', function () {
    Queue::fake();
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);

    $page = CreateKnowledgePage::run($workspace, $owner, [
        'title' => 'Page to delete',
        'body' => 'Some content.',
    ]);

    $this->actingAs($owner);

    // Navigate to the page in the tree, then delete it.
    $browser = visit('/knowledge/'.$page->id);
    $browser->assertSee('Page to delete');

    // Open the delete dialog and confirm.
    $browser->click('button:has-text("Delete")');
    $browser->assertPresent('[data-test="confirm-delete-knowledge"]');
    $browser->click('[data-test="confirm-delete-knowledge"]');

    // Back on the index — the page is gone from the tree.
    $browser->assertPathIs('/knowledge');
    $browser->assertDontSee('Page to delete');

    // The trash now lists the page.
    app(WorkspaceContext::class)->set($workspace);
    $trash = visit('/knowledge/trash');
    $trash->assertSee('Trash')
        ->assertSee('Page to delete');

    $trash->assertNoSmoke();
});

it('restores a page from the trash', function () {
    Queue::fake();
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);

    $page = CreateKnowledgePage::run($workspace, $owner, [
        'title' => 'Trashed page',
        'body' => 'Content.',
    ]);
    $page->forceFill([
        'deleted_by_id' => $owner->id,
        'deleted_reason' => 'manual',
    ])->save();
    $page->delete();

    $this->actingAs($owner);

    $browser = visit('/knowledge/trash');
    $browser->assertSee('Trashed page');

    // Open the restore dialog and confirm.
    $browser->click('[data-test="trash-restore-'.$page->id.'"]');
    $browser->assertPresent('[data-test="confirm-restore-knowledge"]');
    $browser->click('[data-test="confirm-restore-knowledge"]');

    // Redirected to the restored page.
    $browser->assertPathIs('/knowledge/'.$page->id);
    $browser->assertSee('Trashed page');

    // The page is no longer soft-deleted.
    app(WorkspaceContext::class)->set($workspace);
    expect(KnowledgePage::find($page->id))->not->toBeNull();
    expect(KnowledgePage::find($page->id)->deleted_at)->toBeNull();

    $browser->assertNoSmoke();
});

it('force deletes a page from the trash', function () {
    Queue::fake();
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);

    $page = CreateKnowledgePage::run($workspace, $owner, [
        'title' => 'Page to purge',
        'body' => 'Content.',
    ]);
    $page->forceFill([
        'deleted_by_id' => $owner->id,
        'deleted_reason' => 'manual',
    ])->save();
    $page->delete();

    $this->actingAs($owner);

    $browser = visit('/knowledge/trash');
    $browser->assertSee('Page to purge');

    // Open the force-delete dialog and confirm.
    $browser->click('[data-test="trash-force-delete-'.$page->id.'"]');
    $browser->assertPresent('[data-test="confirm-force-delete-knowledge"]');
    $browser->click('[data-test="confirm-force-delete-knowledge"]');

    // Stays on the trash page, the row is gone.
    $browser->assertPathIs('/knowledge/trash');
    $browser->assertDontSee('Page to purge');

    // The page is permanently gone from the database.
    app(WorkspaceContext::class)->set($workspace);
    expect(KnowledgePage::withTrashed()->find($page->id))->toBeNull();

    $browser->assertNoSmoke();
});

it('shows the empty state when the trash has no pages', function () {
    Queue::fake();
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);

    $this->actingAs($owner);

    $browser = visit('/knowledge/trash');
    $browser->assertSee('Trash')
        ->assertSee('The trash is empty.');

    $browser->assertNoSmoke();
});

it('shows metadata for trashed pages', function () {
    Queue::fake();
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);

    $page = CreateKnowledgePage::run($workspace, $owner, [
        'title' => 'Page with metadata',
        'body' => 'Content.',
        'project_id' => $project->id,
    ]);
    $page->forceFill([
        'deleted_by_id' => $owner->id,
        'deleted_reason' => 'manual',
    ])->save();
    $page->delete();

    $this->actingAs($owner);

    $browser = visit('/knowledge/trash');
    $browser->assertSee('Page with metadata')
        ->assertSee($project->name)
        ->assertSee($owner->name)
        ->assertSee('Manual');

    $browser->assertNoSmoke();
});

it('navigates to the trash from the sidebar', function () {
    Queue::fake();
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);

    $this->actingAs($owner);

    $browser = visit('/knowledge');
    $browser->assertSee('Knowledge');

    // The sidebar has a dedicated Trash nav entry.
    $browser->click('a:has-text("Trash")');
    $browser->assertPathIs('/knowledge/trash');
    $browser->assertSee('The trash is empty.');

    $browser->assertNoSmoke();
});

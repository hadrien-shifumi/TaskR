<?php

use App\Actions\Knowledge\CreateKnowledgePage;
use App\Actions\Projects\CreateProject;
use App\Models\KnowledgePage;
use App\Models\Membership;
use App\Models\ProjectMember;
use App\Models\User;
use App\Support\WorkspaceContext;

it('walks a newcomer through the knowledge tree', function () {
    Queue::fake();
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);

    $root = CreateKnowledgePage::run($workspace, $owner, [
        'title' => 'Glossaire métier',
        'body' => "## Vocabulaire\nLe règlement couvre le cycle complet des invoices.",
    ]);
    CreateKnowledgePage::run($workspace, $owner, [
        'title' => 'Convention de branches',
        'body' => 'Les branches suivent la convention feat/…',
        'parent_id' => $root->id,
    ]);

    $this->actingAs($owner);

    $page = visit('/knowledge');

    // No scope chosen yet: the tree waits for a pick, but the landing
    // shows the recently updated pages instead of a dead end.
    $page->assertSee('Select a project')
        ->assertSee('Recently updated')
        ->assertSee('Glossaire métier');

    // Picking the transverse scope reveals the tree (root only: branches
    // start folded); expanding the root shows its sub-page.
    $page->click('@filter-select-project');
    $page->click('[role="option"]:has-text("Cross-workspace only")');
    $page->assertSee('Glossaire métier')
        ->assertDontSee('Convention de branches');
    $page->click("@knowledge-toggle-{$root->id}");
    $page->assertSee('Convention de branches');

    $page->navigate('/knowledge/'.$root->id);
    $page->assertSee('Vocabulaire')
        ->assertSee('cycle complet des invoices')
        ->assertNoSmoke();
});

it('creates a sub-page in one click with the parent pre-filled', function () {
    Queue::fake();
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);

    $parent = CreateKnowledgePage::run($workspace, $owner, [
        'title' => 'Guide racine',
        'body' => 'Corps du guide.',
    ]);

    $this->actingAs($owner);

    $page = visit('/knowledge/'.$parent->id);

    // The article header offers one-click sub-page creation.
    $page->assertSee('New sub-page');
    $page->click('@new-sub-page');

    // The dialog opens with the parent pre-filled.
    $page->assertSeeIn('[role="dialog"]', 'Guide racine');

    $page->fill('new-knowledge-title', 'Sous-page créée');
    $page->fill('[role="dialog"] .ProseMirror', 'Corps de la sous-page.');
    $page->click('button:has-text("Create")');

    $page->assertSee('Sous-page créée');

    app(WorkspaceContext::class)->set($workspace);
    $child = KnowledgePage::query()->where('title', 'Sous-page créée')->firstOrFail();
    expect($child->parent_id)->toBe($parent->id);

    $page->assertNoSmoke();
});

it('creates then edits a knowledge page from the UI', function () {
    Queue::fake();
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);

    $this->actingAs($owner);

    $page = visit('/knowledge');
    // No scope chosen yet: the tree shows the pick-a-scope invite.
    $page->assertSee('Select a project');

    $page->click('header button:has-text("New page")');
    $page->fill('new-knowledge-title', 'Onboarding');
    $page->fill('[role="dialog"] .ProseMirror', 'Bienvenue dans le workspace.');
    $page->click('button:has-text("Create")');

    $page->assertSee('Onboarding')
        ->assertSee('Bienvenue dans le workspace.');

    // The editor is full-page now: no dialog, the form replaces the
    // article in place.
    $page->click('button:has-text("Edit")');
    $page->assertPresent('@knowledge-edit-form')
        ->assertMissing('[role="dialog"]');
    $page->fill('edit-knowledge-title', 'Onboarding v2');
    $page->click('@edit-knowledge-save');

    $page->assertSee('Onboarding v2');

    app(WorkspaceContext::class)->set($workspace);
    expect(KnowledgePage::query()->where('title', 'Onboarding v2')->exists())->toBeTrue();

    $page->assertNoSmoke();
});

it('picks a parent with the searchable hierarchy and never offers a cycle', function () {
    Queue::fake();
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);

    $root = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Manuel produit', 'body' => 'Corps du manuel.']);
    $child = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Installation', 'body' => 'Corps installation.', 'parent_id' => $root->id]);
    CreateKnowledgePage::run($workspace, $owner, ['title' => 'Prérequis', 'body' => 'Corps prérequis.', 'parent_id' => $child->id]);
    $other = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Notes réunions', 'body' => 'Corps des notes.']);
    // A second root, untouched by the scenario: it must stay offered
    // when the first root is being edited (empty-query browsing).
    CreateKnowledgePage::run($workspace, $owner, ['title' => 'FAQ générale', 'body' => 'Corps FAQ.']);

    $this->actingAs($owner);

    // Attach "Notes réunions" under "Installation" through the combobox.
    $page = visit('/knowledge/'.$other->id);
    $page->click('button:has-text("Edit")');
    $page->click('@knowledge-edit-options-toggle');
    $page->fill('edit-knowledge-parent', 'Installation');
    $page->wait(1);
    // The result carries its path in the tree — the hierarchy is visible.
    $page->assertSeeIn('@knowledge-parent-results', 'Manuel produit');
    $page->click('[data-test="knowledge-parent-result"]:has-text("Installation")');
    $page->click('@edit-knowledge-save');

    $page->assertSee('Notes réunions')
        ->assertSee('Manuel produit');

    app(WorkspaceContext::class)->set($workspace);
    $fresh = KnowledgePage::query()->where('title', 'Notes réunions')->firstOrFail();
    expect($fresh->parent_id)->toBe($child->id);

    // Editing the root: its whole subtree is removed upstream — the
    // cycle can no longer be selected, not merely refused at save time.
    $page->navigate('/knowledge/'.$root->id);
    $page->click('button:has-text("Edit")');
    $page->click('@knowledge-edit-options-toggle');
    $page->wait(1); // roots fetch on picker mount
    // Nothing typed yet: the roots are offered — but the edited root
    // itself never is (a cycle must not be proposed, even untyped).
    $page->assertDontSeeIn('@knowledge-parent-results', 'Manuel produit');
    $page->assertSeeIn('@knowledge-parent-results', 'FAQ générale');
    $page->fill('edit-knowledge-parent', 'Prérequis');
    $page->wait(1);
    $page->assertDontSeeIn('@knowledge-parent-results', 'Prérequis');
    $page->assertSeeIn('@knowledge-parent-results', 'No matching page.');

    $page->assertNoSmoke();
});

it('proposes restoring an unsaved draft after an accidental close', function () {
    Queue::fake();
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);

    $page = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Page brouillon', 'body' => 'Corps initial.']);

    $this->actingAs($owner);

    $browser = visit('/knowledge/'.$page->id);
    $browser->click('button:has-text("Edit")');
    $browser->fill('edit-knowledge-title', 'Titre jamais enregistré');
    $browser->wait(1); // the draft auto-save lands before the close
    $browser->click('button:has-text("Cancel")');
    $browser->assertSee('Page brouillon');

    // Reopening proposes the lost work instead of forgetting it.
    $browser->click('button:has-text("Edit")');
    $browser->assertSee('An unsaved draft was found.');
    $browser->click('@knowledge-draft-restore');
    $browser->assertValue('edit-knowledge-title', 'Titre jamais enregistré');

    // Saving purges the draft: no proposal on the next open.
    $browser->click('@edit-knowledge-save');
    $browser->assertSee('Titre jamais enregistré');
    $browser->click('button:has-text("Edit")');
    $browser->assertDontSee('An unsaved draft was found.');

    $browser->assertNoSmoke();
});

it('returns the relevant page from semantic search', function () {
    Queue::fake();
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);

    config()->set('knowledge.embeddings', [
        'base_url' => 'https://embeddings.test/v1',
        'api_key' => 'test-key',
        'model' => 'text-embedding-3-small',
        'dimensions' => 2,
    ]);

    Http::fake([
        'embeddings.test/v1/embeddings' => Http::response(['data' => [['embedding' => [1.0, 0.0]]]]),
    ]);

    KnowledgePage::factory()->vectorized([1.0, 0.0])->create([
        'workspace_id' => $workspace->id,
        'title' => 'Processus de règlement et invoices',
        'body' => 'Le cycle de règlement des factures clients.',
    ]);

    $this->actingAs($owner);

    $page = visit('/knowledge');
    $page->fill('input[aria-label="Semantic search"]', 'comment se passe la facturation d\'un client');
    $page->wait(1);

    $page->assertSee('Processus de règlement et invoices')
        ->assertSee('Le cycle de règlement des factures clients')
        ->assertNoSmoke();
});

it('hides out-of-scope and transverse pages from a guest on the knowledge tree', function () {
    Queue::fake();
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);

    $client = CreateProject::run($workspace, $owner, ['name' => 'Projet client']);
    $internal = CreateProject::run($workspace, $owner, ['name' => 'Interne secret']);
    CreateKnowledgePage::run($workspace, $owner, [
        'title' => 'Page client', 'body' => 'Corps', 'project_id' => $client->id,
    ]);
    CreateKnowledgePage::run($workspace, $owner, [
        'title' => 'Page interne', 'body' => 'Corps', 'project_id' => $internal->id,
    ]);
    CreateKnowledgePage::run($workspace, $owner, ['title' => 'Page transverse', 'body' => 'Corps']);

    $guest = User::factory()->create();
    Membership::create([
        'user_id' => $guest->id,
        'workspace_id' => $workspace->id,
        'role' => 'guest',
    ]);
    ProjectMember::create([
        'project_id' => $client->id,
        'user_id' => $guest->id,
        'role' => 'member',
    ]);

    $this->actingAs($guest);

    $page = visit('/knowledge');

    // No scope chosen: the landing shows the perimeter's recent pages —
    // nothing out of scope ever leaks, tree or recent list.
    $page->assertSee('Select a project')
        ->assertSee('Page client')
        ->assertDontSee('Page interne')
        ->assertDontSee('Page transverse');

    // The select only offers the guest's perimeter projects.
    $page->click('@filter-select-project');
    $page->assertDontSee('Interne secret');

    $page->click('[role="option"]:has-text("Projet client")');
    $page->assertSee('Page client')
        ->assertDontSee('Page interne')
        ->assertDontSee('Page transverse')
        ->assertDontSee('Interne secret');

    $page->assertNoSmoke();
});

it('re-parents a page by dragging it onto another page of the tree', function () {
    Queue::fake();
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);

    $alpha = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
    $target = CreateKnowledgePage::run($workspace, $owner, [
        'title' => 'Manuel', 'body' => 'Corps du manuel.', 'project_id' => $alpha->id,
    ]);
    $dragged = CreateKnowledgePage::run($workspace, $owner, [
        'title' => 'Installation', 'body' => 'Corps installation.', 'project_id' => $alpha->id,
    ]);
    $child = CreateKnowledgePage::run($workspace, $owner, [
        'title' => 'Prérequis', 'body' => 'Corps prérequis.',
        'parent_id' => $dragged->id, 'project_id' => $alpha->id,
    ]);

    $this->actingAs($owner);

    $page = visit('/knowledge?project='.$alpha->id);
    $page->assertSee('Installation');

    // Drag "Installation" onto "Manuel": it becomes its sub-page.
    $page->drag(
        '[data-test="knowledge-tree-row-'.$dragged->id.'"]',
        '[data-test="knowledge-tree-row-'.$target->id.'"]',
    );

    $page->waitForText('has been moved in the tree');

    app(WorkspaceContext::class)->set($workspace);
    expect($dragged->refresh()->parent_id)->toBe($target->id);
    // The dragged page's own subtree is untouched.
    expect($child->refresh()->parent_id)->toBe($dragged->id);

    $page->assertNoSmoke();
});

it('refuses to drop a page onto its own subtree', function () {
    Queue::fake();
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);

    $alpha = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
    $target = CreateKnowledgePage::run($workspace, $owner, [
        'title' => 'Manuel', 'body' => 'Corps du manuel.', 'project_id' => $alpha->id,
    ]);
    $child = CreateKnowledgePage::run($workspace, $owner, [
        'title' => 'Installation', 'body' => 'Corps installation.',
        'parent_id' => $target->id, 'project_id' => $alpha->id,
    ]);
    $grandChild = CreateKnowledgePage::run($workspace, $owner, [
        'title' => 'Prérequis', 'body' => 'Corps prérequis.',
        'parent_id' => $child->id, 'project_id' => $alpha->id,
    ]);

    $this->actingAs($owner);

    $page = visit('/knowledge?project='.$alpha->id);

    // Expand the branch so the grandchild row is rendered before dragging.
    $page->click("@knowledge-toggle-{$target->id}");
    $page->click("@knowledge-toggle-{$child->id}");

    // Dropping "Manuel" onto its grandchild would create a cycle: the
    // client blocks the drop, no request ever leaves the browser.
    $page->drag(
        '[data-test="knowledge-tree-row-'.$target->id.'"]',
        '[data-test="knowledge-tree-row-'.$grandChild->id.'"]',
    );

    app(WorkspaceContext::class)->set($workspace);
    expect($target->refresh()->parent_id)->toBeNull();
    expect($child->refresh()->parent_id)->toBe($target->id);
    expect($grandChild->refresh()->parent_id)->toBe($child->id);

    $page->assertNoSmoke();
});

it('shows word count and reading time under the article', function () {
    Queue::fake();
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);

    $page = CreateKnowledgePage::run($workspace, $owner, [
        'title' => 'Long article',
        'body' => str_repeat('Mot contenu ', 120),
    ]);

    $this->actingAs($owner);

    $browser = visit('/knowledge/'.$page->id);
    $browser->assertPresent('[data-test="knowledge-reading-meta"]');
    $browser->assertSeeIn('[data-test="knowledge-reading-meta"]', '240 words');
    $browser->assertSeeIn('[data-test="knowledge-reading-meta"]', '1 min read');

    $browser->assertNoSmoke();
});

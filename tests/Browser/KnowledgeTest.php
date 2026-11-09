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

    // No scope chosen yet: the tree waits for a pick.
    $page->assertSee('Select a project')
        ->assertDontSee('Glossaire métier');

    // Picking the transverse scope reveals the tree.
    $page->click('@filter-select-project');
    $page->click('[role="option"]:has-text("Cross-workspace only")');
    $page->assertSee('Glossaire métier')
        ->assertSee('Convention de branches');

    $page->navigate('/knowledge/'.$root->id);
    $page->assertSee('Vocabulaire')
        ->assertSee('cycle complet des invoices')
        ->assertNoSmoke();
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

    $page->click('button:has-text("Edit")');
    $page->fill('edit-knowledge-title', 'Onboarding v2');
    $page->click('button:has-text("Save")');

    $page->assertSee('Onboarding v2');

    app(WorkspaceContext::class)->set($workspace);
    expect(KnowledgePage::query()->where('title', 'Onboarding v2')->exists())->toBeTrue();

    $page->assertNoSmoke();
});

it('returns the relevant page from semantic search', function () {
    Queue::fake();
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);

    config()->set('knowledge.embeddings', [
        'base_url' => 'https://embeddings.test/v1',
        'api_key' => 'test-key',
        'model' => 'text-embedding-3-small',
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

    // No scope chosen: the tree ships empty — nothing leaks by default.
    $page->assertSee('Select a project')
        ->assertDontSee('Page client')
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

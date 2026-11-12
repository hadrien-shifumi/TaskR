<?php

use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Models\Issue;
use App\Models\KnowledgePage;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\Queue;

/**
 * Browser fixtures for the issue-knowledge scenarios (knowledge base:
 * "Lier des pages de la base de connaissance à une issue").
 * `seedIssueKnowledgeScenario()` creates an owner with a workspace/project
 * and one issue — no page linked yet. `stubKnowledgeEmbeddings()` fakes the
 * embeddings endpoint so the dialog's semantic search ranks the pages
 * vectorized with [1.0, 0.0] first (KnowledgeTest pattern).
 *
 * @return array{owner: User, workspace: Workspace, project: Project, issue: Issue}
 */
function seedIssueKnowledgeScenario(): array
{
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);

    $issue = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Ship the delivery note',
    ]);

    return [
        'owner' => $owner,
        'workspace' => $workspace,
        'project' => $project,
        'issue' => $issue,
    ];
}

/** Stubs the embeddings API the link dialog's semantic search calls. */
function stubKnowledgeEmbeddings(): void
{
    config()->set('knowledge.embeddings', [
        'base_url' => 'https://embeddings.test/v1',
        'api_key' => 'test-key',
        'model' => 'text-embedding-3-small',
        'dimensions' => 2,
    ]);

    Http::fake([
        'embeddings.test/v1/embeddings' => Http::response(['data' => [['embedding' => [1.0, 0.0]]]]),
    ]);
}

/** Links the pages to the issue through the pivot (seeding, no journal). */
function linkKnowledgePages(Issue $issue, KnowledgePage ...$pages): void
{
    $issue->knowledgePages()->sync(collect($pages)->map(fn (KnowledgePage $page) => $page->id)->all());
}

/** Creates a guest membership scoped to the given project. */
function createGuestScopedToProject(Workspace $workspace, Project $project): User
{
    $guest = User::factory()->create();
    Membership::create([
        'user_id' => $guest->id,
        'workspace_id' => $workspace->id,
        'role' => 'guest',
    ]);
    ProjectMember::create([
        'project_id' => $project->id,
        'user_id' => $guest->id,
        'role' => 'member',
    ]);

    return $guest;
}

it('links a page to the issue from the dialog semantic search', function () {
    ['owner' => $owner, 'workspace' => $workspace, 'issue' => $issue] = seedIssueKnowledgeScenario();
    stubKnowledgeEmbeddings();

    KnowledgePage::factory()->vectorized([1.0, 0.0])->create([
        'workspace_id' => $workspace->id,
        'title' => 'Invoice settlement process',
        'body' => 'How client invoices are settled, end to end.',
    ]);

    $this->actingAs($owner);

    $page = visit('/issues/'.$issue->id);

    // The knowledge section starts empty.
    $page->assertVisible('@no-knowledge-pages');
    $page->assertNotPresent('@knowledge-page-link');

    $page->click('@knowledge-dialog-open');
    $page->assertSee('Manage linked pages');
    $page->assertPresent('@knowledge-candidates');

    // Semantic search (debounced 400 ms): the vectorized page surfaces.
    $page->fill('@knowledge-search-input', 'how are client invoices settled');
    $page->wait(1);

    $candidate = 'label:has-text("Invoice settlement process")';
    $page->assertPresent($candidate);
    $page->assertAttributeContains($candidate.' [data-test="knowledge-candidate"]', 'aria-checked', 'false');
    $page->click($candidate);
    $page->assertAttributeContains($candidate.' [data-test="knowledge-candidate"]', 'aria-checked', 'true');

    $page->click('@knowledge-submit');
    $page->waitForEvent('networkidle');

    // The dialog closes, the page lands in the fiche's knowledge section
    // and the journal tells it in plain words.
    $page->assertNotPresent('[data-slot="dialog-content"]');
    $page->assertVisible('@knowledge-page-link');
    $page->assertSee('Invoice settlement process');
    $page->assertNotPresent('@no-knowledge-pages');

    // The journal folded with the rest of the fiche: open its accordion.
    $page->click('[data-test="activity-accordion-trigger"]');
    $page->assertSee('Pages linked');

    app(WorkspaceContext::class)->set($workspace);
    expect($issue->fresh()->knowledgePages()->count())->toBe(1);

    $page->assertNoSmoke();
});

it('unlinks a page by unchecking it in the dialog', function () {
    ['owner' => $owner, 'workspace' => $workspace, 'issue' => $issue] = seedIssueKnowledgeScenario();

    $doc = KnowledgePage::factory()->create([
        'workspace_id' => $workspace->id,
        'title' => 'Invoice settlement process',
    ]);
    linkKnowledgePages($issue, $doc);

    $this->actingAs($owner);

    $page = visit('/issues/'.$issue->id);
    $page->assertVisible('@knowledge-page-link');
    $page->assertNotPresent('@no-knowledge-pages');

    $page->click('@knowledge-dialog-open');
    $page->assertSee('Manage linked pages');

    // The linked page is pre-checked without any search.
    $linked = 'label:has-text("Invoice settlement process")';
    $page->assertAttributeContains($linked.' [data-test="knowledge-linked-page"]', 'aria-checked', 'true');

    $page->click($linked);
    $page->assertAttributeContains($linked.' [data-test="knowledge-linked-page"]', 'aria-checked', 'false');

    $page->click('@knowledge-submit');
    $page->waitForEvent('networkidle');

    // The dialog closes, the section is empty again.
    $page->assertNotPresent('[data-slot="dialog-content"]');
    $page->assertVisible('@no-knowledge-pages');
    $page->assertNotPresent('@knowledge-page-link');

    // The journal folded with the rest of the fiche: open its accordion.
    $page->click('[data-test="activity-accordion-trigger"]');
    $page->assertSee('Pages unlinked');

    app(WorkspaceContext::class)->set($workspace);
    expect($issue->fresh()->knowledgePages()->count())->toBe(0);

    $page->assertNoSmoke();
});

it('keeps a trashed page linked with its in-trash flag and clears the flag after restoration', function () {
    Queue::fake();
    ['owner' => $owner, 'workspace' => $workspace, 'issue' => $issue] = seedIssueKnowledgeScenario();

    $doc = KnowledgePage::factory()->create([
        'workspace_id' => $workspace->id,
        'title' => 'Settlement doc',
    ]);
    linkKnowledgePages($issue, $doc);

    $this->actingAs($owner);

    // Trash the page through the knowledge UI.
    $knowledge = visit('/knowledge/'.$doc->id);
    $knowledge->click('button:has-text("Delete")');
    $knowledge->assertPresent('[data-test="confirm-delete-knowledge"]');
    $knowledge->click('[data-test="confirm-delete-knowledge"]');
    $knowledge->assertPathIs('/knowledge');

    // The fiche keeps the link and flags it "in trash".
    $page = visit('/issues/'.$issue->id);
    $page->assertVisible('@knowledge-page-link');
    $page->assertSee('Settlement doc');
    $page->assertVisible('@knowledge-page-trashed');
    $page->assertNotPresent('@no-knowledge-pages');

    // In the dialog the trashed page is locked: saving would detach it.
    $page->click('@knowledge-dialog-open');
    $page->assertSee('in trash');
    $page->assertDisabled('[data-test="knowledge-linked-page"]');
    $page->assertSee('A page in the trash cannot stay linked: saving removes its link.');
    $page->click('[data-slot="dialog-content"] button:has-text("Cancel")');

    // Restore from the trash: the flag leaves the fiche, the link survived.
    $trash = visit('/knowledge/trash');
    $trash->assertSee('Settlement doc');
    $trash->click('[data-test="trash-restore-'.$doc->id.'"]');
    $trash->assertPresent('[data-test="confirm-restore-knowledge"]');
    $trash->click('[data-test="confirm-restore-knowledge"]');
    $trash->assertPathIs('/knowledge/'.$doc->id);

    $restored = visit('/issues/'.$issue->id);
    $restored->assertVisible('@knowledge-page-link');
    $restored->assertNotPresent('@knowledge-page-trashed');

    app(WorkspaceContext::class)->set($workspace);
    expect($issue->fresh()->knowledgePages()->count())->toBe(1);

    $page->assertNoSmoke();
    $restored->assertNoSmoke();
});

it('drops the link when a linked page is force deleted from the trash', function () {
    ['owner' => $owner, 'workspace' => $workspace, 'issue' => $issue] = seedIssueKnowledgeScenario();

    $doc = KnowledgePage::factory()->create([
        'workspace_id' => $workspace->id,
        'title' => 'Obsolete doc',
    ]);
    linkKnowledgePages($issue, $doc);

    // Trashed like the knowledge UI does (author + reason metadata).
    $doc->forceFill(['deleted_by_id' => $owner->id, 'deleted_reason' => 'manual'])->save();
    $doc->delete();

    $this->actingAs($owner);

    $trash = visit('/knowledge/trash');
    $trash->assertSee('Obsolete doc');
    $trash->click('[data-test="trash-force-delete-'.$doc->id.'"]');
    $trash->assertPresent('[data-test="confirm-force-delete-knowledge"]');
    $trash->click('[data-test="confirm-force-delete-knowledge"]');
    $trash->assertPathIs('/knowledge/trash');
    $trash->assertDontSee('Obsolete doc');

    // The fiche drops the dead link: the section is empty again.
    $page = visit('/issues/'.$issue->id);
    $page->assertVisible('@no-knowledge-pages');
    $page->assertNotPresent('@knowledge-page-link');

    app(WorkspaceContext::class)->set($workspace);
    expect($issue->fresh()->knowledgePages()->count())->toBe(0)
        ->and(KnowledgePage::withTrashed()->find($doc->id))->toBeNull();

    $page->assertNoSmoke();
});

it('hides out-of-perimeter linked pages from a guest on the fiche', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);

    $internal = CreateProject::run($workspace, $owner, ['name' => 'Interne secret']);
    $issue = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Ticket client',
    ]);

    $transversePage = KnowledgePage::factory()->create([
        'workspace_id' => $workspace->id,
        'title' => 'Page transverse',
    ]);
    $internalPage = KnowledgePage::factory()->create([
        'workspace_id' => $workspace->id,
        'project_id' => $internal->id,
        'title' => 'Page interne',
    ]);
    linkKnowledgePages($issue, $transversePage, $internalPage);

    $this->actingAs(createGuestScopedToProject($workspace, $project));

    $page = visit('/issues/'.$issue->id);

    // The fiche drops the out-of-perimeter linked pages entirely.
    $page->assertVisible('@no-knowledge-pages');
    $page->assertDontSee('Page transverse');
    $page->assertDontSee('Page interne');

    $page->assertNoSmoke();
});

it('proposes and links only the pages inside a guest perimeter', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    stubKnowledgeEmbeddings();

    $internal = CreateProject::run($workspace, $owner, ['name' => 'Interne secret']);
    $issue = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Ticket client',
    ]);

    $clientPage = KnowledgePage::factory()->vectorized([1.0, 0.0])->create([
        'workspace_id' => $workspace->id,
        'project_id' => $project->id,
        'title' => 'Page client',
        'body' => 'Documentation du projet client.',
    ]);
    KnowledgePage::factory()->vectorized([1.0, 0.0])->create([
        'workspace_id' => $workspace->id,
        'title' => 'Page transverse',
        'body' => 'Documentation transverse du workspace.',
    ]);
    KnowledgePage::factory()->vectorized([1.0, 0.0])->create([
        'workspace_id' => $workspace->id,
        'project_id' => $internal->id,
        'title' => 'Page interne',
        'body' => 'Documentation du projet interne.',
    ]);

    $this->actingAs(createGuestScopedToProject($workspace, $project));

    $page = visit('/issues/'.$issue->id);
    $page->click('@knowledge-dialog-open');

    // The search only proposes the in-scope page: the transverse and
    // out-of-scope pages never surface for a guest.
    $page->fill('@knowledge-search-input', 'documentation');
    $page->wait(1);

    $page->assertSee('Page client');
    $page->assertDontSee('Page transverse');
    $page->assertDontSee('Page interne');

    $page->click('label:has-text("Page client")');
    $page->click('@knowledge-submit');
    $page->waitForEvent('networkidle');

    // The link lands: the fiche shows exactly the in-scope page.
    $page->assertNotPresent('[data-slot="dialog-content"]');
    $page->assertVisible('@knowledge-page-link');
    $page->assertSee('Page client');

    app(WorkspaceContext::class)->set($workspace);
    $linkedIds = $issue->fresh()->knowledgePages()->pluck('knowledge_pages.id')->all();
    expect($linkedIds)->toContain($clientPage->id);

    // The list badge counts only the perimeter: one linked page on the row.
    $list = visit('/issues');
    $list->assertSeeIn('[data-test="issue-row-'.$issue->id.'"] [data-test="issue-knowledge-badge"]', '1');

    $page->assertNoSmoke();
    $list->assertNoSmoke();
});

it('shows the knowledge badge on the list row, the kanban card and the board card', function () {
    ['owner' => $owner, 'workspace' => $workspace, 'project' => $project, 'issue' => $issue] = seedIssueKnowledgeScenario();

    $alpha = KnowledgePage::factory()->create(['workspace_id' => $workspace->id, 'title' => 'Alpha doc']);
    $zeta = KnowledgePage::factory()->create(['workspace_id' => $workspace->id, 'title' => 'Zeta doc']);
    linkKnowledgePages($issue, $alpha, $zeta);

    $plain = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Issue without pages',
    ]);

    $this->actingAs($owner);

    // List row: two linked pages with the tooltip titles; nothing on the
    // issue without pages.
    $list = visit('/issues');
    $badge = '[data-test="issue-row-'.$issue->id.'"] [data-test="issue-knowledge-badge"]';
    $list->assertSeeIn($badge, '2')
        ->assertAttributeContains($badge, 'title', 'Alpha doc, Zeta doc');
    $list->assertNotPresent('[data-test="issue-row-'.$plain->id.'"] [data-test="issue-knowledge-badge"]');

    // Kanban strip of the same page.
    $kanban = visit('/issues?view=kanban');
    $kanban->assertSeeIn('[data-test="kanban-card-'.$issue->id.'"] [data-test="issue-knowledge-badge"]', '2');
    $kanban->assertNotPresent('[data-test="kanban-card-'.$plain->id.'"] [data-test="issue-knowledge-badge"]');

    // Project board card.
    $board = visit('/projects/'.$project->id.'/board');
    $board->assertSeeIn('[data-test="board-card-'.$issue->id.'"] [data-test="issue-knowledge-badge"]', '2');
    $board->assertNotPresent('[data-test="board-card-'.$plain->id.'"] [data-test="issue-knowledge-badge"]');

    $list->assertNoSmoke();
    $kanban->assertNoSmoke();
    $board->assertNoSmoke();
});

it('warns when the semantic search is not configured in the link dialog', function () {
    // Hermetic degraded mode whatever the local .env provides.
    config()->set('knowledge.embeddings', [
        'base_url' => null,
        'api_key' => null,
        'model' => null,
    ]);

    ['owner' => $owner, 'workspace' => $workspace, 'issue' => $issue] = seedIssueKnowledgeScenario();

    $this->actingAs($owner);

    $page = visit('/issues/'.$issue->id);
    $page->click('@knowledge-dialog-open');
    $page->fill('@knowledge-search-input', 'anything');
    $page->wait(1);

    // Degraded mode: an explicit banner replaces the results, the dialog
    // stays open and usable.
    $page->assertVisible('@knowledge-search-unavailable');
    $page->assertNotPresent('@knowledge-candidate');
    $page->assertPresent('[data-slot="dialog-content"]');

    $page->assertNoSmoke();
});

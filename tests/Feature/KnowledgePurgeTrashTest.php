<?php

namespace Tests\Feature;

use App\Actions\Knowledge\CreateKnowledgePage;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\KnowledgePage;
use App\Models\KnowledgePageVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class KnowledgePurgeTrashTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function setupWorkspace(): array
    {
        Queue::fake();

        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace];
    }

    public function test_purge_deletes_pages_older_than_30_days(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        // Page supprimée il y a 31 jours.
        $oldPage = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Vieille page',
            'body' => 'Contenu.',
        ]);
        $oldPage->forceFill(['deleted_at' => Carbon::now()->subDays(31)])->save();

        $this->artisan('knowledge:purge-trash')
            ->assertSuccessful();

        $this->assertNull(KnowledgePage::withoutWorkspaceScope()->withTrashed()->find($oldPage->id));
    }

    public function test_purge_keeps_pages_newer_than_30_days(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        // Page supprimée il y a 10 jours.
        $recentPage = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page récente',
            'body' => 'Contenu.',
        ]);
        $recentPage->forceFill(['deleted_at' => Carbon::now()->subDays(10)])->save();

        $this->artisan('knowledge:purge-trash')
            ->assertSuccessful();

        $this->assertNotNull(KnowledgePage::withoutWorkspaceScope()->withTrashed()->find($recentPage->id));
    }

    public function test_purge_keeps_pages_deleted_exactly_30_days_ago(): void
    {
        // Limite : 30 jours pile — la page est conservée (<= cutoff, pas <).
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page limite',
            'body' => 'Contenu.',
        ]);
        // 30 jours moins 1 minute = encore dans la rétention.
        $page->forceFill(['deleted_at' => Carbon::now()->subDays(30)->addMinute()])->save();

        $this->artisan('knowledge:purge-trash')
            ->assertSuccessful();

        $this->assertNotNull(KnowledgePage::withoutWorkspaceScope()->withTrashed()->find($page->id));
    }

    public function test_purge_with_custom_days_option(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        // Page supprimée il y a 10 jours.
        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page test',
            'body' => 'Contenu.',
        ]);
        $page->forceFill(['deleted_at' => Carbon::now()->subDays(10)])->save();

        // Avec --days=7, la page (10 jours) doit être purgée.
        $this->artisan('knowledge:purge-trash', ['--days' => 7])
            ->assertSuccessful();

        $this->assertNull(KnowledgePage::withoutWorkspaceScope()->withTrashed()->find($page->id));
    }

    public function test_purge_dry_run_does_not_delete(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page dry run',
            'body' => 'Contenu.',
        ]);
        $page->forceFill(['deleted_at' => Carbon::now()->subDays(31)])->save();

        $this->artisan('knowledge:purge-trash', ['--dry-run' => true])
            ->assertSuccessful();

        // La page existe toujours.
        $this->assertNotNull(KnowledgePage::withoutWorkspaceScope()->withTrashed()->find($page->id));
    }

    public function test_purge_processes_all_workspaces(): void
    {
        Queue::fake();

        // Créer 2 workspaces avec des pages supprimées.
        $owner1 = User::factory()->create();
        $workspace1 = CreateWorkspace::run($owner1, ['name' => 'WS1']);
        app(WorkspaceContext::class)->set($workspace1);
        $page1 = CreateKnowledgePage::run($workspace1, $owner1, [
            'title' => 'Page WS1',
            'body' => 'Contenu.',
        ]);
        $page1->forceFill(['deleted_at' => Carbon::now()->subDays(31)])->save();

        $owner2 = User::factory()->create();
        $workspace2 = CreateWorkspace::run($owner2, ['name' => 'WS2']);
        app(WorkspaceContext::class)->set($workspace2);
        $page2 = CreateKnowledgePage::run($workspace2, $owner2, [
            'title' => 'Page WS2',
            'body' => 'Contenu.',
        ]);
        $page2->forceFill(['deleted_at' => Carbon::now()->subDays(31)])->save();

        // La commande ne dépend pas du workspace courant.
        $this->artisan('knowledge:purge-trash')
            ->assertSuccessful();

        // Les deux pages sont supprimées.
        $this->assertNull(KnowledgePage::withoutWorkspaceScope()->withTrashed()->find($page1->id));
        $this->assertNull(KnowledgePage::withoutWorkspaceScope()->withTrashed()->find($page2->id));
    }

    public function test_purge_with_no_trashed_pages(): void
    {
        $this->setupWorkspace();

        // Aucune page en corbeille.
        $this->artisan('knowledge:purge-trash')
            ->expectsOutput('No trashed pages to purge.')
            ->assertSuccessful();
    }

    public function test_purge_does_not_affect_non_trashed_pages(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $activePage = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page active',
            'body' => 'Contenu.',
        ]);

        $this->artisan('knowledge:purge-trash')
            ->assertSuccessful();

        // La page active existe toujours.
        $this->assertNotNull(KnowledgePage::find($activePage->id));
    }

    public function test_purge_force_deletes_children_versions_cascade(): void
    {
        // Si une page a des versions, elles doivent être supprimées en cascade.
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page avec versions',
            'body' => 'Contenu.',
        ]);

        // Créer une version.
        KnowledgePageVersion::create([
            'page_id' => $page->id,
            'title' => $page->title,
            'body' => $page->body,
            'editor_id' => $owner->id,
        ]);

        $page->forceFill(['deleted_at' => Carbon::now()->subDays(31)])->save();

        $this->artisan('knowledge:purge-trash')
            ->assertSuccessful();

        // Page supprimée.
        $this->assertNull(KnowledgePage::withoutWorkspaceScope()->withTrashed()->find($page->id));
        // Versions supprimées en cascade.
        $this->assertSame(0, KnowledgePageVersion::where('page_id', $page->id)->count());
    }
}

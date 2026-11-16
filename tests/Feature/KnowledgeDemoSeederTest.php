<?php

namespace Tests\Feature;

use App\Actions\Knowledge\CreateKnowledgePage;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\KnowledgePage;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Database\Seeders\KnowledgeDemoSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class KnowledgeDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Local reality: no embeddings API key configured. Force the
        // disabled path and a null queue so no dispatch ever reaches a
        // real endpoint — the seeder applies the same override itself.
        config(['knowledge.embeddings.api_key' => null]);
        config(['queue.default' => 'null']);
    }

    private function seedDemo(): void
    {
        Artisan::call('db:seed', ['--class' => KnowledgeDemoSeeder::class]);
    }

    /**
     * Two workspaces with distinct creation dates and the demo projects of
     * the oldest one (one archived, deliberately no "TaskR App" project to
     * exercise the transverse fallback).
     *
     * @return array{0: User, 1: Workspace, 2: Workspace}
     */
    private function setupWorkspaces(): array
    {
        $owner = User::factory()->create();
        $first = CreateWorkspace::run($owner, ['name' => 'Premier']);
        $second = CreateWorkspace::run($owner, ['name' => 'Deuxième']);

        // Distinct created_at: the seeder targets the oldest workspace.
        Workspace::query()->whereKey($second->getKey())->update(['created_at' => now()->addMinute()]);

        app(WorkspaceContext::class)->set($first);

        CreateProject::run($first, $owner, ['name' => 'Website Redesign']);
        CreateProject::run($first, $owner, ['name' => 'Mobile App']);
        $archived = CreateProject::run($first, $owner, ['name' => 'Q1 Marketing Site']);
        $archived->forceFill(['archived_at' => now()])->save();

        return [$owner, $first, $second];
    }

    private function pagesOf(Workspace $workspace): Collection
    {
        return KnowledgePage::withoutWorkspaceScope()
            ->withTrashed()
            ->where('workspace_id', $workspace->id)
            ->get();
    }

    private function pageOf(Workspace $workspace, string $title): KnowledgePage
    {
        $page = $this->pagesOf($workspace)->firstWhere('title', $title);
        $this->assertNotNull($page, "La page « {$title} » doit exister.");

        return $page;
    }

    public function test_seeder_targets_the_oldest_workspace(): void
    {
        [$owner, $first, $second] = $this->setupWorkspaces();

        $this->seedDemo();

        $pages = $this->pagesOf($first);
        $this->assertTrue($pages->isNotEmpty());
        $this->assertTrue($pages->every(
            fn (KnowledgePage $page): bool => $page->workspace_id === $first->id,
        ));
        $this->assertCount(0, $this->pagesOf($second));
    }

    public function test_seeder_creates_a_three_level_hierarchy(): void
    {
        [$owner, $first] = $this->setupWorkspaces();

        $this->seedDemo();

        $root = $this->pageOf($first, "Manuel de l'équipe");
        $this->assertNull($root->parent_id);
        $this->assertGreaterThanOrEqual(3, $root->children()->count());

        $mid = $root->children()->where('title', 'Processus de développement')->first();
        $this->assertNotNull($mid);
        $this->assertGreaterThanOrEqual(1, $mid->children()->count());

        // A grandchild exists: three levels in the tree.
        $grandchild = $mid->children()->where('title', 'Revue de code')->first();
        $this->assertNotNull($grandchild);
        $this->assertSame($mid->id, $grandchild->parent_id);
    }

    public function test_seeder_attaches_pages_to_existing_projects_by_name(): void
    {
        [$owner, $first] = $this->setupWorkspaces();
        $website = Project::withoutWorkspaceScope()->where('workspace_id', $first->id)->where('name', 'Website Redesign')->firstOrFail();
        $mobile = Project::withoutWorkspaceScope()->where('workspace_id', $first->id)->where('name', 'Mobile App')->firstOrFail();
        $archived = Project::withoutWorkspaceScope()->where('workspace_id', $first->id)->where('name', 'Q1 Marketing Site')->firstOrFail();

        $this->seedDemo();

        $this->assertTrue(KnowledgePage::withoutWorkspaceScope()->where('project_id', $website->id)->exists());
        $this->assertTrue(KnowledgePage::withoutWorkspaceScope()->where('project_id', $mobile->id)->exists());
        // The archived project is ignored; its demo page fell back to transverse.
        $this->assertFalse(KnowledgePage::withoutWorkspaceScope()->withTrashed()->where('project_id', $archived->id)->exists());
    }

    public function test_seeder_creates_rich_markdown_content(): void
    {
        [$owner, $first] = $this->setupWorkspaces();

        $this->seedDemo();

        $guide = $this->pageOf($first, 'Guide de démarrage');
        $this->assertStringContainsString('## ', $guide->body, 'Le sommaire a besoin de sections h2.');
        $this->assertStringContainsString('```', $guide->body, 'Un bloc de code est attendu.');
        $this->assertStringContainsString('| ', $guide->body, 'Un tableau est attendu.');
        $this->assertStringContainsString('- [ ]', $guide->body, 'Une liste de tâches est attendue.');
        $this->assertStringContainsString('> ', $guide->body, 'Une citation est attendue.');

        $this->assertNotNull($this->pageOf($first, 'Glossaire'));
    }

    public function test_seeder_creates_a_version_history(): void
    {
        [$owner, $first] = $this->setupWorkspaces();

        $this->seedDemo();

        $guide = $this->pageOf($first, 'Guide de démarrage');
        $this->assertGreaterThanOrEqual(3, $guide->versions()->count());
    }

    public function test_seeder_fills_the_trash_with_two_reasons(): void
    {
        [$owner, $first] = $this->setupWorkspaces();

        $this->seedDemo();

        $trashed = $this->pagesOf($first)->filter(fn (KnowledgePage $page): bool => $page->deleted_at !== null);
        $this->assertCount(2, $trashed);

        $manual = $trashed->firstWhere('deleted_reason', 'manual');
        $this->assertNotNull($manual, 'Une suppression manuelle est attendue.');
        $this->assertNull($manual->project_id);

        $cascade = $trashed->firstWhere('deleted_reason', 'cascade_project');
        $this->assertNotNull($cascade, 'Une suppression en cascade projet est attendue.');
        // The cascade detaches the page from its project before trashing.
        $this->assertNull($cascade->project_id);
    }

    public function test_seeder_is_idempotent(): void
    {
        [$owner, $first] = $this->setupWorkspaces();

        $this->seedDemo();

        $pages = $this->pagesOf($first)->count();
        $versions = KnowledgePage::withoutWorkspaceScope()->withTrashed()->where('workspace_id', $first->id)->get()->sum(fn (KnowledgePage $page): int => $page->versions()->count());
        $trashed = $this->pagesOf($first)->filter(fn (KnowledgePage $page): bool => $page->deleted_at !== null)->count();

        $this->seedDemo();

        $this->assertSame($pages, $this->pagesOf($first)->count(), 'Aucun doublon de page.');
        $this->assertSame(
            $versions,
            KnowledgePage::withoutWorkspaceScope()->withTrashed()->where('workspace_id', $first->id)->get()->sum(fn (KnowledgePage $page): int => $page->versions()->count()),
            'Aucune version supplémentaire.',
        );
        $this->assertSame($trashed, $this->pagesOf($first)->filter(fn (KnowledgePage $page): bool => $page->deleted_at !== null)->count(), 'Aucune entrée de corbeille supplémentaire.');
    }

    public function test_seeder_preserves_preexisting_data(): void
    {
        [$owner, $first] = $this->setupWorkspaces();

        $existing = CreateKnowledgePage::run($first, $owner, [
            'title' => 'Page préexistante',
            'body' => 'Contenu original.',
        ]);

        $this->seedDemo();

        $existing->refresh();
        $this->assertSame('Page préexistante', $existing->title);
        $this->assertSame('Contenu original.', $existing->body);
        $this->assertNull($existing->deleted_at);
        $this->assertSame(1, $existing->versions()->count(), 'Aucune version ajoutée à la page existante.');
    }

    public function test_seeder_refuses_to_run_in_production(): void
    {
        [$owner, $first] = $this->setupWorkspaces();

        $this->app->detectEnvironment(fn (): string => 'production');

        $this->expectException(HttpException::class);

        // Direct run: the console kernel swallows command exceptions, the
        // guard itself lives in run().
        (new KnowledgeDemoSeeder)->run();
    }

    public function test_seeder_leaves_no_doomed_vectorization_jobs(): void
    {
        [$owner, $first] = $this->setupWorkspaces();

        // Simulate the local .env: database queue, no embeddings key.
        config(['queue.default' => 'database']);

        $this->seedDemo();

        $this->assertSame(0, DB::table('jobs')->count(), 'Aucune tâche de vectorisation ne doit s\'empiler.');
    }
}

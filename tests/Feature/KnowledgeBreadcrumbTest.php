<?php

namespace Tests\Feature;

use App\Actions\Knowledge\CreateKnowledgePage;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class KnowledgeBreadcrumbTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace, 2: Project}
     */
    private function setupWorkspace(): array
    {
        Queue::fake();

        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $project = CreateProject::run($workspace, $owner, ['name' => 'Facturation']);

        return [$owner, $workspace, $project];
    }

    public function test_selected_exposes_parent_chain_root_first(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $grandParent = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Architecture',
            'body' => 'Root of the subtree.',
            'project_id' => $project->id,
        ]);
        $parent = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Module facturation',
            'body' => 'Middle of the subtree.',
            'project_id' => $project->id,
            'parent_id' => $grandParent->id,
        ]);
        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'TVA',
            'body' => 'Leaf of the subtree.',
            'project_id' => $project->id,
            'parent_id' => $parent->id,
        ]);

        $this->actingAs($owner)
            ->get(route('knowledge.show', $page->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $assert) => $assert
                ->component('Knowledge/Index')
                ->where('selected.id', $page->id)
                ->has('selected.parentChain', 2)
                ->where('selected.parentChain.0.id', $grandParent->id)
                ->where('selected.parentChain.0.title', 'Architecture')
                ->where('selected.parentChain.1.id', $parent->id)
                ->where('selected.parentChain.1.title', 'Module facturation'));
    }

    public function test_root_page_has_empty_parent_chain(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Glossaire métier',
            'body' => 'Root page without parent.',
        ]);

        $this->actingAs($owner)
            ->get(route('knowledge.show', $page->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $assert) => $assert
                ->component('Knowledge/Index')
                ->has('selected.parentChain', 0));
    }

    public function test_force_deleted_parent_makes_child_a_root(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $parent = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Parent',
            'body' => 'Will be force deleted.',
        ]);
        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Enfant',
            'body' => 'Stays behind.',
            'parent_id' => $parent->id,
        ]);

        // Hard delete fires the nullOnDelete FK on parent_id: the child
        // becomes a root instead of pointing at a missing row.
        $parent->forceDelete();
        $page = $page->refresh();

        $this->assertNull($page->parent_id);

        $this->actingAs($owner)
            ->get(route('knowledge.show', $page->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $assert) => $assert
                ->component('Knowledge/Index')
                ->where('selected.parentId', null)
                ->has('selected.parentChain', 0));
    }

    public function test_transverse_page_parent_chain_works(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $root = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Transverse racine',
            'body' => 'No project.',
        ]);
        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Transverse enfant',
            'body' => 'No project either.',
            'parent_id' => $root->id,
        ]);

        $this->actingAs($owner)
            ->get(route('knowledge.show', $page->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $assert) => $assert
                ->component('Knowledge/Index')
                ->where('selected.projectId', null)
                ->has('selected.parentChain', 1)
                ->where('selected.parentChain.0.id', $root->id)
                ->where('selected.parentChain.0.title', 'Transverse racine'));
    }
}

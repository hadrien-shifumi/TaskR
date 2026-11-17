<?php

namespace Tests\Feature;

use App\Actions\Knowledge\CreateKnowledgePage;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Membership;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class KnowledgeParentSearchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace];
    }

    public function test_descendant_ids_walk_the_whole_subtree(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $root = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Racine', 'body' => 'Corps.']);
        $child = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Enfant', 'body' => 'Corps.', 'parent_id' => $root->id]);
        $grandchild = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Petit-enfant', 'body' => 'Corps.', 'parent_id' => $child->id]);
        CreateKnowledgePage::run($workspace, $owner, ['title' => 'Hors arbre', 'body' => 'Corps.']);

        $descendants = $root->descendantIds();

        $this->assertSame([$child->id, $grandchild->id], $descendants);
        $this->assertNotContains($root->id, $descendants);
    }

    public function test_parent_search_finds_pages_by_title_within_the_workspace(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Guide de style', 'body' => 'Corps.']);
        CreateKnowledgePage::run($workspace, $owner, ['title' => 'Facturation', 'body' => 'Corps.']);

        $this->actingAs($owner)
            ->getJson(route('knowledge.parents', ['q' => 'style']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $page->id)
            ->assertJsonPath('0.title', 'Guide de style');
    }

    public function test_parent_search_returns_the_ancestor_path_for_indentation(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $root = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Racine', 'body' => 'Corps.']);
        $child = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Enfant', 'body' => 'Corps.', 'parent_id' => $root->id]);
        $grandchild = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Petit-enfant', 'body' => 'Corps.', 'parent_id' => $child->id]);

        $this->actingAs($owner)
            ->getJson(route('knowledge.parents', ['q' => 'Petit-enfant']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $grandchild->id)
            ->assertJsonPath('0.path.0.id', $root->id)
            ->assertJsonPath('0.path.0.title', 'Racine')
            ->assertJsonPath('0.path.1.id', $child->id)
            ->assertJsonPath('0.path.1.title', 'Enfant');

        // A root page carries an empty path: nothing to indent.
        $this->actingAs($owner)
            ->getJson(route('knowledge.parents', ['q' => 'Racine']))
            ->assertOk()
            ->assertJsonPath('0.path', []);
    }

    public function test_parent_search_excludes_the_edited_page_and_its_descendants(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $root = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Racine', 'body' => 'Corps.']);
        $child = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Enfant', 'body' => 'Corps.', 'parent_id' => $root->id]);
        $grandchild = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Petit-enfant', 'body' => 'Corps.', 'parent_id' => $child->id]);
        $sibling = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Cousine', 'body' => 'Corps.']);

        // Editing the root: the root and its whole subtree must never be
        // proposed as a parent — the cycle becomes unselectable, not just
        // refused at save time.
        $this->actingAs($owner)
            ->getJson(route('knowledge.parents', ['q' => '', 'exclude' => $root->id]))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $sibling->id);

        // Editing a mid-tree page: its own subtree disappears, the rest
        // of the tree stays reachable.
        $this->actingAs($owner)
            ->getJson(route('knowledge.parents', ['q' => 'Petit-enfant', 'exclude' => $child->id]))
            ->assertOk()
            ->assertJsonCount(0);

        // Same query without the exclusion finds the grandchild.
        $this->actingAs($owner)
            ->getJson(route('knowledge.parents', ['q' => 'Petit-enfant']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $grandchild->id);

        // Empty query with a mid-tree exclusion: every root remains
        // browsable (Racine and Cousine).
        $this->actingAs($owner)
            ->getJson(route('knowledge.parents', ['q' => '', 'exclude' => $child->id]))
            ->assertOk()
            ->assertJsonCount(2);
    }

    public function test_parent_search_without_exclusion_returns_every_matching_page(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $root = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Racine', 'body' => 'Corps.']);
        $child = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Enfant', 'body' => 'Corps.', 'parent_id' => $root->id]);

        // Creation flow: no page being edited, nothing is excluded — the
        // search reaches deep pages as well as roots.
        $this->actingAs($owner)
            ->getJson(route('knowledge.parents', ['q' => 'enfant']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $child->id);
    }

    public function test_parent_search_is_scoped_to_the_guest_perimeter(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $inScope = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $outOfScope = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

        $inScopePage = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Page du perimetre', 'body' => 'Corps.', 'project_id' => $inScope->id]);
        CreateKnowledgePage::run($workspace, $owner, ['title' => 'Page hors perimetre', 'body' => 'Corps.', 'project_id' => $outOfScope->id]);
        CreateKnowledgePage::run($workspace, $owner, ['title' => 'Page transverse', 'body' => 'Corps.']);

        $guest = User::factory()->create();
        Membership::create([
            'user_id' => $guest->id,
            'workspace_id' => $workspace->id,
            'role' => 'guest',
        ]);
        ProjectMember::create([
            'project_id' => $inScope->id,
            'user_id' => $guest->id,
            'role' => 'member',
        ]);

        $this->actingAs($guest)
            ->getJson(route('knowledge.parents', ['q' => 'page']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $inScopePage->id);
    }

    public function test_parent_search_never_returns_pages_of_another_workspace(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();
        CreateKnowledgePage::run($workspace, $owner, ['title' => 'Guide de style', 'body' => 'Corps.']);

        $otherOwner = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($otherOwner, ['name' => 'Other WS']);
        app(WorkspaceContext::class)->set($otherWorkspace);
        CreateKnowledgePage::run($otherWorkspace, $otherOwner, ['title' => 'Guide de style', 'body' => 'Corps.']);

        $this->withSession(['workspace_id' => $workspace->id]);

        $this->actingAs($owner)
            ->getJson(route('knowledge.parents', ['q' => 'style']))
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_empty_query_returns_root_pages_only(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $root = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Racine', 'body' => 'Corps.']);
        CreateKnowledgePage::run($workspace, $owner, ['title' => 'Enfant', 'body' => 'Corps.', 'parent_id' => $root->id]);

        // No query typed yet: the combobox browses the top of the tree.
        $this->actingAs($owner)
            ->getJson(route('knowledge.parents', ['q' => '']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $root->id);
    }

    public function test_the_limit_is_capped_and_respected(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        foreach (['Alpha un', 'Alpha deux', 'Alpha trois'] as $title) {
            CreateKnowledgePage::run($workspace, $owner, ['title' => $title, 'body' => 'Corps.']);
        }

        $this->actingAs($owner)
            ->getJson(route('knowledge.parents', ['q' => 'alpha', 'limit' => 2]))
            ->assertOk()
            ->assertJsonCount(2);

        $this->actingAs($owner)
            ->getJson(route('knowledge.parents', ['q' => 'alpha', 'limit' => 0]))
            ->assertJsonValidationErrors(['limit']);

        $this->actingAs($owner)
            ->getJson(route('knowledge.parents', ['q' => 'alpha', 'limit' => 51]))
            ->assertJsonValidationErrors(['limit']);
    }
}

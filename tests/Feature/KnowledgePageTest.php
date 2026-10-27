<?php

namespace Tests\Feature;

use App\Actions\Knowledge\CreateKnowledgePage;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\KnowledgePage;
use App\Models\Membership;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class KnowledgePageTest extends TestCase
{
    use RefreshDatabase;

    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $project = CreateProject::run($workspace, $owner, ['name' => 'Facturation']);

        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        return [$owner, $workspace, $project, $member];
    }

    public function test_member_creates_transverse_page(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->post('/knowledge', [
                'title' => 'Glossaire métier',
                'body' => 'Definitions of the domain vocabulary.',
            ])
            ->assertRedirect();

        $page = KnowledgePage::query()->where('title', 'Glossaire métier')->firstOrFail();

        $this->assertSame($workspace->id, $page->workspace_id);
        $this->assertNull($page->project_id);
        $this->assertNull($page->parent_id);
        $this->assertSame($owner->id, (int) $page->last_editor_id);
    }

    public function test_member_creates_page_attached_to_project_and_parent(): void
    {
        Queue::fake();
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $root = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Architecture',
            'body' => 'Overview of the system.',
        ]);

        $this->actingAs($owner)
            ->post('/knowledge', [
                'title' => 'Module facturation',
                'body' => 'How invoicing works.',
                'project_id' => $project->id,
                'parent_id' => $root->id,
            ])
            ->assertRedirect();

        $page = KnowledgePage::query()->where('title', 'Module facturation')->firstOrFail();

        $this->assertSame($project->id, $page->project_id);
        $this->assertSame($root->id, $page->parent_id);
    }

    public function test_project_from_another_workspace_is_rejected(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $stranger = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($stranger, ['name' => 'Other']);
        app(WorkspaceContext::class)->set($otherWorkspace);
        $foreignProject = CreateProject::run($otherWorkspace, $stranger, ['name' => 'Étranger']);
        app(WorkspaceContext::class)->set($workspace);

        $this->actingAs($owner)
            ->post('/knowledge', [
                'title' => 'Espion',
                'body' => 'Should not pass.',
                'project_id' => $foreignProject->id,
            ])
            ->assertSessionHasErrors('project_id');

        $this->assertSame(0, KnowledgePage::query()->count());
    }

    public function test_parent_from_another_workspace_is_rejected(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $stranger = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($stranger, ['name' => 'Other']);
        app(WorkspaceContext::class)->set($otherWorkspace);
        $foreignParent = CreateKnowledgePage::run($otherWorkspace, $stranger, [
            'title' => 'Their page',
            'body' => 'Their content.',
        ]);
        app(WorkspaceContext::class)->set($workspace);

        $this->actingAs($owner)
            ->post('/knowledge', [
                'title' => 'Espion',
                'body' => 'Should not pass.',
                'parent_id' => $foreignParent->id,
            ])
            ->assertSessionHasErrors('parent_id');

        $this->assertSame(0, KnowledgePage::query()->count());
    }

    public function test_page_of_another_workspace_is_not_found(): void
    {
        Queue::fake();
        [$owner, $workspace, , $member] = $this->setupWorkspace();

        $stranger = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($stranger, ['name' => 'Other']);
        app(WorkspaceContext::class)->set($otherWorkspace);
        $foreignPage = CreateKnowledgePage::run($otherWorkspace, $stranger, [
            'title' => 'Their page',
            'body' => 'Their content.',
        ]);
        app(WorkspaceContext::class)->set($workspace);

        $this->actingAs($member)
            ->get("/knowledge/{$foreignPage->id}")
            ->assertNotFound();

        $this->actingAs($member)
            ->patch("/knowledge/{$foreignPage->id}", [
                'title' => 'Hijacked',
                'body' => 'Nope.',
            ])
            ->assertNotFound();
    }

    public function test_edit_by_another_member_records_last_editor(): void
    {
        Queue::fake();
        [$owner, $workspace, , $member] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Onboarding',
            'body' => 'First version.',
        ]);

        $this->assertSame($owner->id, (int) $page->last_editor_id);

        $this->actingAs($member)
            ->patch("/knowledge/{$page->id}", [
                'title' => 'Onboarding',
                'body' => 'Second version by another member.',
            ])
            ->assertRedirect();

        $page->refresh();

        $this->assertSame($member->id, (int) $page->last_editor_id);
        $this->assertSame('Second version by another member.', $page->body);
    }

    public function test_cycle_in_hierarchy_is_rejected(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $a = CreateKnowledgePage::run($workspace, $owner, ['title' => 'A', 'body' => 'A.']);
        $b = CreateKnowledgePage::run($workspace, $owner, ['title' => 'B', 'body' => 'B.', 'parent_id' => $a->id]);

        // B is a child of A: making B the parent of A must be refused.
        $this->actingAs($owner)
            ->patch("/knowledge/{$a->id}", [
                'title' => 'A',
                'body' => 'A.',
                'parent_id' => $b->id,
            ])
            ->assertStatus(422);

        $this->assertSame($a->id, $b->refresh()->parent_id);
        $this->assertNull($a->refresh()->parent_id);

        // Self-parenting is refused too.
        $this->actingAs($owner)
            ->patch("/knowledge/{$a->id}", [
                'title' => 'A',
                'body' => 'A.',
                'parent_id' => $a->id,
            ])
            ->assertStatus(422);
    }

    public function test_page_can_be_moved_without_cycle(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $a = CreateKnowledgePage::run($workspace, $owner, ['title' => 'A', 'body' => 'A.']);
        $b = CreateKnowledgePage::run($workspace, $owner, ['title' => 'B', 'body' => 'B.']);
        $child = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Child', 'body' => 'C.', 'parent_id' => $a->id]);

        $this->actingAs($owner)
            ->patch("/knowledge/{$child->id}", [
                'title' => 'Child',
                'body' => 'C.',
                'parent_id' => $b->id,
            ])
            ->assertRedirect();

        $this->assertSame($b->id, $child->refresh()->parent_id);
    }

    public function test_delete_is_refused_while_children_exist(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $parent = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Parent', 'body' => 'P.']);
        CreateKnowledgePage::run($workspace, $owner, ['title' => 'Child 1', 'body' => 'C1.', 'parent_id' => $parent->id]);
        CreateKnowledgePage::run($workspace, $owner, ['title' => 'Child 2', 'body' => 'C2.', 'parent_id' => $parent->id]);

        $this->actingAs($owner)
            ->delete("/knowledge/{$parent->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('knowledge_pages', ['id' => $parent->id]);
    }

    public function test_childless_page_can_be_deleted(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Lone', 'body' => 'L.']);

        $this->actingAs($owner)
            ->delete("/knowledge/{$page->id}")
            ->assertRedirect(route('knowledge.index'));

        // Soft delete: page is hidden from default queries but still exists
        // with trash metadata (who deleted it and why).
        $this->assertNull(KnowledgePage::find($page->id));
        $trashed = KnowledgePage::withTrashed()->findOrFail($page->id);
        $this->assertNotNull($trashed->deleted_at);
        $this->assertSame($owner->id, (int) $trashed->deleted_by_id);
        $this->assertSame('manual', $trashed->deleted_reason);
    }

    public function test_knowledge_index_renders_tree_and_reader(): void
    {
        Queue::fake();
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $root = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Glossaire', 'body' => 'Vocabulaire du domaine.']);
        CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Module facturation',
            'body' => 'Détail du module.',
            'project_id' => $project->id,
            'parent_id' => $root->id,
        ]);

        $this->actingAs($owner)
            ->get('/knowledge')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Knowledge/Index')
                ->has('pages', 2)
                ->where('pages.0.title', 'Glossaire'));

        $this->actingAs($owner)
            ->get("/knowledge/{$root->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Knowledge/Index')
                ->where('selected.body', 'Vocabulaire du domaine.'));
    }
}

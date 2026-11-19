<?php

namespace Tests\Feature;

use App\Actions\Knowledge\CreateKnowledgePage;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\KnowledgePageVersion;
use App\Models\Membership;
use App\Models\ProjectMember;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class KnowledgeMovePageTest extends TestCase
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

    public function test_move_reparents_page_under_new_parent(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();
        $project = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);

        $parentA = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Racine A', 'body' => 'Corps A.', 'project_id' => $project->id,
        ]);
        $parentB = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Racine B', 'body' => 'Corps B.', 'project_id' => $project->id,
        ]);
        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Enfant', 'body' => 'Corps enfant.',
            'parent_id' => $parentA->id, 'project_id' => $project->id,
        ]);
        $versionsBefore = $page->versions()->count();

        $this->actingAs($owner)
            ->patch("/knowledge/{$page->id}/move", ['parent_id' => $parentB->id])
            ->assertRedirect();

        $page->refresh();

        $this->assertSame($parentB->id, $page->parent_id);
        // A move never touches the content: same project, same title/body,
        // and no new version is captured.
        $this->assertSame($project->id, $page->project_id);
        $this->assertSame('Enfant', $page->title);
        $this->assertSame('Corps enfant.', $page->body);
        $this->assertSame($versionsBefore, $page->versions()->count());
    }

    public function test_move_to_root_clears_parent(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $parent = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Racine', 'body' => 'Corps.']);
        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Enfant', 'body' => 'Corps.', 'parent_id' => $parent->id,
        ]);

        $this->actingAs($owner)
            ->patch("/knowledge/{$page->id}/move", ['parent_id' => null])
            ->assertRedirect();

        $this->assertNull($page->refresh()->parent_id);
    }

    public function test_move_rejects_self_parent(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, ['title' => 'A', 'body' => 'Corps.']);

        $this->actingAs($owner)
            ->patch("/knowledge/{$page->id}/move", ['parent_id' => $page->id])
            ->assertStatus(422);

        $this->assertNull($page->refresh()->parent_id);
    }

    public function test_move_rejects_cycle(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $a = CreateKnowledgePage::run($workspace, $owner, ['title' => 'A', 'body' => 'Corps A.']);
        $b = CreateKnowledgePage::run($workspace, $owner, ['title' => 'B', 'body' => 'Corps B.', 'parent_id' => $a->id]);

        // B is a child of A: moving A under B would create a cycle.
        $this->actingAs($owner)
            ->patch("/knowledge/{$a->id}/move", ['parent_id' => $b->id])
            ->assertStatus(422);

        $this->assertNull($a->refresh()->parent_id);
        $this->assertSame($a->id, $b->refresh()->parent_id);
    }

    public function test_move_rejects_parent_from_another_workspace(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, ['title' => 'A', 'body' => 'Corps A.']);

        $stranger = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($stranger, ['name' => 'Other']);
        app(WorkspaceContext::class)->set($otherWorkspace);
        $foreignParent = CreateKnowledgePage::run($otherWorkspace, $stranger, [
            'title' => 'Their page', 'body' => 'Their content.',
        ]);
        app(WorkspaceContext::class)->set($workspace);

        $this->actingAs($owner)
            ->patch("/knowledge/{$page->id}/move", ['parent_id' => $foreignParent->id])
            ->assertSessionHasErrors('parent_id');

        $this->assertNull($page->refresh()->parent_id);
    }

    public function test_move_rejects_parent_in_another_project(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();
        $alpha = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $beta = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page Alpha', 'body' => 'Corps.', 'project_id' => $alpha->id,
        ]);
        $betaParent = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page Beta', 'body' => 'Corps.', 'project_id' => $beta->id,
        ]);

        // A drag never changes the project: a parent of another project is refused.
        $this->actingAs($owner)
            ->patch("/knowledge/{$page->id}/move", ['parent_id' => $betaParent->id])
            ->assertStatus(422);

        $page->refresh();

        $this->assertSame($alpha->id, $page->project_id);
        $this->assertNull($page->parent_id);
    }

    public function test_move_rejects_parent_out_of_guest_scope(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();
        $inScope = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $outOfScope = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page client', 'body' => 'Corps.', 'project_id' => $inScope->id,
        ]);
        $hiddenParent = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page interne', 'body' => 'Corps.', 'project_id' => $outOfScope->id,
        ]);

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
            ->patch("/knowledge/{$page->id}/move", ['parent_id' => $hiddenParent->id])
            ->assertSessionHasErrors('parent_id');
    }

    public function test_move_rejects_guest_writer_on_project_page(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();
        $project = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);

        $root = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Racine', 'body' => 'Corps.', 'project_id' => $project->id,
        ]);
        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page client', 'body' => 'Corps.', 'project_id' => $project->id,
        ]);

        $guest = User::factory()->create();
        Membership::create([
            'user_id' => $guest->id,
            'workspace_id' => $workspace->id,
            'role' => 'guest',
        ]);
        // Roster line Guest on the project: reads everything, writes
        // nothing — a move is a write.
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $guest->id,
            'role' => 'guest',
        ]);

        $this->actingAs($guest)
            ->patch("/knowledge/{$page->id}/move", ['parent_id' => $root->id])
            ->assertSessionHasErrors('parent_id');

        $this->assertNull($page->refresh()->parent_id);
    }

    public function test_move_page_of_another_workspace_404(): void
    {
        Queue::fake();
        $owner1 = User::factory()->create();
        $workspace1 = CreateWorkspace::run($owner1, ['name' => 'WS1']);
        app(WorkspaceContext::class)->set($workspace1);
        $page = CreateKnowledgePage::run($workspace1, $owner1, ['title' => 'Page WS1', 'body' => 'Corps.']);

        $owner2 = User::factory()->create();
        $workspace2 = CreateWorkspace::run($owner2, ['name' => 'WS2']);
        app(WorkspaceContext::class)->set($workspace2);
        $parent2 = CreateKnowledgePage::run($workspace2, $owner2, ['title' => 'Racine WS2', 'body' => 'Corps.']);

        $this->actingAs($owner2)
            ->patch("/knowledge/{$page->id}/move", ['parent_id' => $parent2->id])
            ->assertNotFound();
    }

    public function test_move_requires_auth(): void
    {
        $this->patch('/knowledge/01h0000000000000000000000000/move', ['parent_id' => null])
            ->assertRedirect('/login');
    }

    public function test_move_records_activity(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $parentA = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Racine A', 'body' => 'Corps A.']);
        $parentB = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Racine B', 'body' => 'Corps B.']);
        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Enfant', 'body' => 'Corps.', 'parent_id' => $parentA->id,
        ]);

        $this->actingAs($owner)
            ->patch("/knowledge/{$page->id}/move", ['parent_id' => $parentB->id])
            ->assertRedirect();

        $event = AuditEvent::query()
            ->where('knowledge_page_id', $page->id)
            ->where('type', AuditEventType::KnowledgePageUpdated)
            ->latest('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame($parentA->id, $event->changes['parent_id']['before'] ?? null);
        $this->assertSame($parentB->id, $event->changes['parent_id']['after'] ?? null);
    }

    public function test_move_nonexistent_parent_rejected(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, ['title' => 'A', 'body' => 'Corps.']);

        $this->actingAs($owner)
            ->patch("/knowledge/{$page->id}/move", ['parent_id' => '01h0000000000000000000000000'])
            ->assertSessionHasErrors('parent_id');
    }

    public function test_move_does_not_create_version(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $parentA = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Racine A', 'body' => 'Corps A.']);
        $parentB = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Racine B', 'body' => 'Corps B.']);
        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Enfant', 'body' => 'Corps.', 'parent_id' => $parentA->id,
        ]);

        $before = KnowledgePageVersion::query()->where('page_id', $page->id)->count();

        $this->actingAs($owner)
            ->patch("/knowledge/{$page->id}/move", ['parent_id' => $parentB->id])
            ->assertRedirect();

        $this->assertSame($before, KnowledgePageVersion::query()->where('page_id', $page->id)->count());
    }
}

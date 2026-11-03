<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\AuditEvent;
use App\Models\Issue;
use App\Models\KnowledgePage;
use App\Models\Membership;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetIssueKnowledgeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace, 2: Issue}
     */
    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);
        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'A']);

        return [$owner, $workspace, $issue];
    }

    /**
     * Guest scoped to one project, with a second project out of reach.
     *
     * @return array{0: User, 1: Workspace, 2: Issue, 3: User}
     */
    private function setupScopedGuest(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $inScope = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $outOfScope = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

        $inScopeIssue = CreateIssue::run($workspace, ['project_id' => $inScope->id, 'title' => 'Dans le perimetre']);
        $outOfScopeIssue = CreateIssue::run($workspace, ['project_id' => $outOfScope->id, 'title' => 'Hors perimetre']);

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

        return [$owner, $workspace, $inScopeIssue, $outOfScopeIssue, $guest];
    }

    public function test_sync_ajoute_et_retire_des_pages(): void
    {
        [$owner, $workspace, $issue] = $this->setupWorkspace();

        $first = KnowledgePage::factory()->create(['workspace_id' => $workspace->id]);
        $second = KnowledgePage::factory()->create(['workspace_id' => $workspace->id]);
        $third = KnowledgePage::factory()->create(['workspace_id' => $workspace->id]);

        $this->actingAs($owner)
            ->post(route('issues.knowledge', $issue), ['page_ids' => [$first->id, $second->id]])
            ->assertRedirect();

        $this->assertDatabaseHas('issue_knowledge_pages', [
            'issue_id' => $issue->id,
            'knowledge_page_id' => $first->id,
        ]);
        $this->assertDatabaseHas('issue_knowledge_pages', [
            'issue_id' => $issue->id,
            'knowledge_page_id' => $second->id,
        ]);

        // The payload is the full desired list: a re-sync without the first
        // page detaches it, without touching the kept one.
        $this->actingAs($owner)
            ->post(route('issues.knowledge', $issue), ['page_ids' => [$second->id, $third->id]])
            ->assertRedirect();

        $this->assertDatabaseMissing('issue_knowledge_pages', [
            'issue_id' => $issue->id,
            'knowledge_page_id' => $first->id,
        ]);
        $this->assertDatabaseHas('issue_knowledge_pages', [
            'issue_id' => $issue->id,
            'knowledge_page_id' => $second->id,
        ]);
        $this->assertDatabaseHas('issue_knowledge_pages', [
            'issue_id' => $issue->id,
            'knowledge_page_id' => $third->id,
        ]);
        $this->assertDatabaseCount('issue_knowledge_pages', 2);
    }

    public function test_page_hors_workspace_refusee(): void
    {
        [$owner, $workspace, $issue] = $this->setupWorkspace();

        $otherOwner = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($otherOwner, ['name' => 'Autre']);
        app(WorkspaceContext::class)->set($otherWorkspace);
        $foreignPage = KnowledgePage::factory()->create(['workspace_id' => $otherWorkspace->id]);
        app(WorkspaceContext::class)->set($workspace);

        $this->actingAs($owner)
            ->post(route('issues.knowledge', $issue), ['page_ids' => [$foreignPage->id]])
            ->assertSessionHasErrors('page_ids.0');

        $this->assertDatabaseCount('issue_knowledge_pages', 0);
    }

    public function test_page_en_corbeille_refusee(): void
    {
        [$owner, $workspace, $issue] = $this->setupWorkspace();

        $page = KnowledgePage::factory()->create(['workspace_id' => $workspace->id]);
        $page->delete();

        $this->actingAs($owner)
            ->post(route('issues.knowledge', $issue), ['page_ids' => [$page->id]])
            ->assertSessionHasErrors('page_ids.0');

        $this->assertDatabaseCount('issue_knowledge_pages', 0);
    }

    public function test_guest_ne_peut_lier_que_son_perimetre(): void
    {
        [$owner, $workspace, $issue, , $guest] = $this->setupScopedGuest();

        $inScopePage = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'project_id' => $issue->project_id,
        ]);
        $outOfScopePage = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'project_id' => CreateProject::run($workspace, $owner, ['name' => 'Gamma'])->id,
        ]);
        $transversePage = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'project_id' => null,
        ]);

        $this->actingAs($guest)
            ->post(route('issues.knowledge', $issue), ['page_ids' => [$outOfScopePage->id]])
            ->assertSessionHasErrors('page_ids');

        $this->assertDatabaseCount('issue_knowledge_pages', 0);

        // Transverse pages (no project) stay reserved to full members.
        $this->actingAs($guest)
            ->post(route('issues.knowledge', $issue), ['page_ids' => [$transversePage->id]])
            ->assertSessionHasErrors('page_ids');

        $this->assertDatabaseCount('issue_knowledge_pages', 0);

        $this->actingAs($guest)
            ->post(route('issues.knowledge', $issue), ['page_ids' => [$inScopePage->id]])
            ->assertRedirect();

        $this->assertDatabaseHas('issue_knowledge_pages', [
            'issue_id' => $issue->id,
            'knowledge_page_id' => $inScopePage->id,
        ]);
    }

    public function test_issue_hors_perimetre_guest_404(): void
    {
        [$owner, $workspace, , $outOfScopeIssue, $guest] = $this->setupScopedGuest();

        $this->actingAs($guest)
            ->post(route('issues.knowledge', $outOfScopeIssue->id), ['page_ids' => []])
            ->assertNotFound();

        $this->assertDatabaseCount('issue_knowledge_pages', 0);
    }

    public function test_audit_emet_les_evenements(): void
    {
        [$owner, $workspace, $issue] = $this->setupWorkspace();

        $first = KnowledgePage::factory()->create(['workspace_id' => $workspace->id]);
        $second = KnowledgePage::factory()->create(['workspace_id' => $workspace->id]);

        $this->actingAs($owner)
            ->post(route('issues.knowledge', $issue), ['page_ids' => [$first->id]])
            ->assertRedirect();

        $linked = AuditEvent::query()->where('type', 'issue.knowledge_linked')->firstOrFail();
        $this->assertSame($issue->id, $linked->issue_id);
        $this->assertSame($owner->id, $linked->actor_id);
        $this->assertSame(['knowledge_pages_added' => [$first->id]], $linked->changes);

        $this->actingAs($owner)
            ->post(route('issues.knowledge', $issue), ['page_ids' => [$second->id]])
            ->assertRedirect();

        $secondLinked = AuditEvent::query()->where('type', 'issue.knowledge_linked')
            ->orderByDesc('created_at')->firstOrFail();
        $this->assertSame(['knowledge_pages_added' => [$second->id]], $secondLinked->changes);

        $unlinked = AuditEvent::query()->where('type', 'issue.knowledge_unlinked')->firstOrFail();
        $this->assertSame($issue->id, $unlinked->issue_id);
        $this->assertSame(['knowledge_pages_removed' => [$first->id]], $unlinked->changes);

        // A sync that changes nothing journals nothing.
        $linkedCount = AuditEvent::query()->where('type', 'issue.knowledge_linked')->count();
        $unlinkedCount = AuditEvent::query()->where('type', 'issue.knowledge_unlinked')->count();

        $this->actingAs($owner)
            ->post(route('issues.knowledge', $issue), ['page_ids' => [$second->id]])
            ->assertRedirect();

        $this->assertSame($linkedCount, AuditEvent::query()->where('type', 'issue.knowledge_linked')->count());
        $this->assertSame($unlinkedCount, AuditEvent::query()->where('type', 'issue.knowledge_unlinked')->count());
    }

    public function test_doublons_dans_page_ids_dedoublonnes(): void
    {
        [$owner, $workspace, $issue] = $this->setupWorkspace();

        $page = KnowledgePage::factory()->create(['workspace_id' => $workspace->id]);

        $this->actingAs($owner)
            ->post(route('issues.knowledge', $issue), ['page_ids' => [$page->id, $page->id]])
            ->assertRedirect();

        $this->assertDatabaseCount('issue_knowledge_pages', 1);
        $this->assertDatabaseHas('issue_knowledge_pages', [
            'issue_id' => $issue->id,
            'knowledge_page_id' => $page->id,
        ]);
    }
}

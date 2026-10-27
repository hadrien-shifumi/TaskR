<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Knowledge\CreateKnowledgePage;
use App\Actions\Knowledge\RestoreKnowledgePage;
use App\Actions\Projects\CreateProject;
use App\Actions\Projects\DeleteProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\KnowledgePage;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class KnowledgeProjectCascadeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace, 2: Project}
     */
    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $project = CreateProject::run($workspace, $owner, ['name' => 'Facturation']);

        return [$owner, $workspace, $project];
    }

    public function test_project_deletion_moves_pages_to_trash(): void
    {
        Queue::fake();
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Doc projet',
            'body' => 'Contenu.',
            'project_id' => $project->id,
        ]);

        DeleteProject::run($project, $owner);

        // La page est en corbeille, pas supprimée définitivement.
        $this->assertNull(KnowledgePage::find($page->id));
        $trashed = KnowledgePage::withTrashed()->findOrFail($page->id);
        $this->assertNotNull($trashed->deleted_at);
        $this->assertSame('cascade_project', $trashed->deleted_reason);
        $this->assertSame($owner->id, (int) $trashed->deleted_by_id);
    }

    public function test_project_deletion_moves_entire_subtree_to_trash(): void
    {
        Queue::fake();
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $parent = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Parent',
            'body' => 'Contenu.',
            'project_id' => $project->id,
        ]);
        $child = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Enfant',
            'body' => 'Contenu.',
            'project_id' => $project->id,
            'parent_id' => $parent->id,
        ]);

        DeleteProject::run($project, $owner);

        // Les deux pages sont en corbeille.
        $this->assertNull(KnowledgePage::find($parent->id));
        $this->assertNull(KnowledgePage::find($child->id));
        $this->assertNotNull(KnowledgePage::withTrashed()->findOrFail($parent->id)->deleted_at);
        $this->assertNotNull(KnowledgePage::withTrashed()->findOrFail($child->id)->deleted_at);
        $this->assertSame('cascade_project', KnowledgePage::withTrashed()->findOrFail($parent->id)->deleted_reason);
        $this->assertSame('cascade_project', KnowledgePage::withTrashed()->findOrFail($child->id)->deleted_reason);
    }

    public function test_project_deletion_does_not_affect_transverse_pages(): void
    {
        Queue::fake();
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $transversePage = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Doc transverse',
            'body' => 'Contenu.',
        ]);
        $projectPage = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Doc projet',
            'body' => 'Contenu.',
            'project_id' => $project->id,
        ]);

        DeleteProject::run($project, $owner);

        // Page transverse : toujours active.
        $this->assertNotNull(KnowledgePage::find($transversePage->id));
        // Page projet : en corbeille.
        $this->assertNull(KnowledgePage::find($projectPage->id));
    }

    public function test_project_deletion_does_not_affect_other_projects_pages(): void
    {
        Queue::fake();
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $otherProject = CreateProject::run($workspace, $owner, ['name' => 'Autre']);

        $page1 = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page projet 1',
            'body' => 'Contenu.',
            'project_id' => $project->id,
        ]);
        $page2 = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page projet 2',
            'body' => 'Contenu.',
            'project_id' => $otherProject->id,
        ]);

        DeleteProject::run($project, $owner);

        // Page du projet supprimé : en corbeille.
        $this->assertNull(KnowledgePage::find($page1->id));
        // Page de l'autre projet : toujours active.
        $this->assertNotNull(KnowledgePage::find($page2->id));
    }

    public function test_restored_page_becomes_transverse_when_project_deleted(): void
    {
        Queue::fake();
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Doc projet',
            'body' => 'Contenu.',
            'project_id' => $project->id,
        ]);

        DeleteProject::run($project, $owner);

        // Restaurer la page.
        $trashed = KnowledgePage::withTrashed()->findOrFail($page->id);
        RestoreKnowledgePage::run($trashed, $owner);

        // La page restaurée est transverse (project_id = null).
        $restored = KnowledgePage::findOrFail($page->id);
        $this->assertNull($restored->project_id);
        $this->assertNull($restored->deleted_at);
    }

    public function test_project_deletion_with_issues_still_blocked(): void
    {
        Queue::fake();
        [$owner, $workspace, $project] = $this->setupWorkspace();

        // Créer une issue dans le projet.
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Tâche bloquante']);

        // Créer une page dans le projet.
        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Doc',
            'body' => 'Contenu.',
            'project_id' => $project->id,
        ]);

        // La suppression est refusée (409) et la page n'est pas mise en corbeille.
        $this->actingAs($owner)
            ->delete("/projects/{$project->id}")
            ->assertStatus(409);

        $this->assertNotNull(KnowledgePage::find($page->id));
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    public function test_project_deletion_via_http(): void
    {
        Queue::fake();
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Doc projet',
            'body' => 'Contenu.',
            'project_id' => $project->id,
        ]);

        $this->actingAs($owner)
            ->delete("/projects/{$project->id}")
            ->assertRedirect();

        // Page en corbeille.
        $trashed = KnowledgePage::withTrashed()->findOrFail($page->id);
        $this->assertNotNull($trashed->deleted_at);
        $this->assertSame('cascade_project', $trashed->deleted_reason);
    }

    public function test_cascade_trash_respects_workspace_isolation(): void
    {
        Queue::fake();

        // Workspace 1.
        $owner1 = User::factory()->create();
        $workspace1 = CreateWorkspace::run($owner1, ['name' => 'WS1']);
        app(WorkspaceContext::class)->set($workspace1);
        $project1 = CreateProject::run($workspace1, $owner1, ['name' => 'Projet WS1']);

        // Workspace 2.
        $owner2 = User::factory()->create();
        $workspace2 = CreateWorkspace::run($owner2, ['name' => 'WS2']);
        app(WorkspaceContext::class)->set($workspace2);
        $project2 = CreateProject::run($workspace2, $owner2, ['name' => 'Projet WS2']);

        // Pages dans chaque workspace.
        app(WorkspaceContext::class)->set($workspace1);
        $page1 = CreateKnowledgePage::run($workspace1, $owner1, [
            'title' => 'Page WS1',
            'body' => 'Contenu.',
            'project_id' => $project1->id,
        ]);

        app(WorkspaceContext::class)->set($workspace2);
        $page2 = CreateKnowledgePage::run($workspace2, $owner2, [
            'title' => 'Page WS2',
            'body' => 'Contenu.',
            'project_id' => $project2->id,
        ]);

        // Supprimer le projet du workspace 1.
        app(WorkspaceContext::class)->set($workspace1);
        DeleteProject::run($project1, $owner1);

        // Page WS1 en corbeille, page WS2 intacte.
        $this->assertNull(KnowledgePage::find($page1->id));
        app(WorkspaceContext::class)->set($workspace2);
        $this->assertNotNull(KnowledgePage::find($page2->id));
    }

    public function test_already_trashed_pages_are_not_re_trashed(): void
    {
        Queue::fake();
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Doc',
            'body' => 'Contenu.',
            'project_id' => $project->id,
        ]);

        // Mettre la page en corbeille manuellement (raison 'manual').
        $page->forceFill([
            'deleted_by_id' => $owner->id,
            'deleted_reason' => 'manual',
        ])->save();
        $page->delete();

        $originalDeletedAt = $page->deleted_at;

        // Supprimer le projet.
        DeleteProject::run($project, $owner);

        // La page garde sa raison 'manual' et sa date originale.
        $trashed = KnowledgePage::withTrashed()->findOrFail($page->id);
        $this->assertSame('manual', $trashed->deleted_reason);
        $this->assertEquals($originalDeletedAt, $trashed->deleted_at);
    }
}

<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Issue;
use App\Models\KnowledgePage;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IssueKnowledgeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Issue + knowledge page, same workspace and project.
     *
     * @return array{0: User, 1: Workspace, 2: Issue, 3: KnowledgePage}
     */
    private function setupIssueAndPage(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);

        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'A']);

        $page = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
        ]);

        return [$owner, $workspace, $issue, $page];
    }

    public function test_migration_cree_le_pivot(): void
    {
        [,, $issue, $page] = $this->setupIssueAndPage();

        $issue->knowledgePages()->attach($page->id);

        $this->assertDatabaseHas('issue_knowledge_pages', [
            'issue_id' => $issue->id,
            'knowledge_page_id' => $page->id,
        ]);

        $columns = Schema::getColumnListing('issue_knowledge_pages');
        $this->assertEqualsCanonicalizing(['issue_id', 'knowledge_page_id'], $columns);

        $this->expectException(QueryException::class);

        DB::table('issue_knowledge_pages')->insert([
            'issue_id' => $issue->id,
            'knowledge_page_id' => $page->id,
        ]);
    }

    public function test_knowledge_pages_relation(): void
    {
        [, , $issue, $page] = $this->setupIssueAndPage();

        $issue->knowledgePages()->sync([$page->id]);

        $this->assertTrue($issue->fresh()->knowledgePages->contains($page));
        $this->assertDatabaseCount('issue_knowledge_pages', 1);
        $this->assertTrue($page->fresh()->linkedIssues->contains($issue));

        $issue->knowledgePages()->detach($page->id);

        $this->assertDatabaseCount('issue_knowledge_pages', 0);
    }

    public function test_cascade_delete_issue(): void
    {
        [,, $issue, $page] = $this->setupIssueAndPage();

        $issue->knowledgePages()->attach($page->id);

        $issue->delete();

        $this->assertDatabaseCount('issue_knowledge_pages', 0);
    }

    public function test_cascade_delete_page(): void
    {
        [, , $issue, $page] = $this->setupIssueAndPage();

        $issue->knowledgePages()->attach($page->id);

        $page->delete();
        $this->assertDatabaseCount('issue_knowledge_pages', 1);

        $page->forceDelete();
        $this->assertDatabaseCount('issue_knowledge_pages', 0);
    }

    public function test_page_en_corbeille_garde_le_lien(): void
    {
        [, , $issue, $page] = $this->setupIssueAndPage();

        $issue->knowledgePages()->attach($page->id);

        $page->delete();

        $this->assertDatabaseHas('issue_knowledge_pages', [
            'issue_id' => $issue->id,
            'knowledge_page_id' => $page->id,
        ]);

        $this->assertCount(0, $issue->fresh()->knowledgePages()->get());

        $trashed = $issue->fresh()->knowledgePages()->withTrashed()->get();
        $this->assertCount(1, $trashed);
        $this->assertTrue($trashed->first()->trashed());
    }
}

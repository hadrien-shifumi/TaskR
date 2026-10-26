<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\StatusCategory;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IssueArchiveTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Shared skeleton for the archive epic: a workspace with its default
     * statuses (one per category) and an empty project.
     *
     * @return array{0: User, 1: Workspace, 2: Project}
     */
    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);

        app(WorkspaceContext::class)->set($workspace);

        $project = $workspace->projects()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Site vitrine',
        ]);

        return [$owner, $workspace, $project];
    }

    private function statusOf(Workspace $workspace, StatusCategory $category): Status
    {
        return $workspace->statuses()
            ->where('category', $category)
            ->firstOrFail();
    }

    private function createIssue(Workspace $workspace, Project $project, StatusCategory $category, array $attributes = []): Issue
    {
        return Issue::create(array_merge([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => ucfirst(fake()->words(3, true)),
            'status_id' => $this->statusOf($workspace, $category)->id,
        ], $attributes));
    }

    public function test_unarchived_scope_excludes_archived_issues(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $active = $this->createIssue($workspace, $project, StatusCategory::Backlog);
        $archived = $this->createIssue($workspace, $project, StatusCategory::Done);
        $archived->update(['archived_at' => now()]);

        $ids = Issue::query()->unarchived()->pluck('id');

        $this->assertTrue($ids->contains($active->id));
        $this->assertFalse($ids->contains($archived->id));
    }

    public function test_archived_scope_returns_only_archived_issues(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $active = $this->createIssue($workspace, $project, StatusCategory::Backlog);
        $archived = $this->createIssue($workspace, $project, StatusCategory::Done);
        $archived->update(['archived_at' => now()]);

        $ids = Issue::query()->archived()->pluck('id');

        $this->assertTrue($ids->contains($archived->id));
        $this->assertFalse($ids->contains($active->id));
    }

    public function test_is_archived_reflects_archived_at_state(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $issue = $this->createIssue($workspace, $project, StatusCategory::Done);

        $this->assertFalse($issue->isArchived());

        $issue->update(['archived_at' => now()]);
        $this->assertTrue($issue->fresh()->isArchived());

        $issue->update(['archived_at' => null]);
        $this->assertFalse($issue->fresh()->isArchived());
    }

    public function test_archived_at_defaults_to_null(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $issue = $this->createIssue($workspace, $project, StatusCategory::InProgress);

        $this->assertNull($issue->fresh()->archived_at);
        $this->assertDatabaseHas('issues', [
            'id' => $issue->id,
            'archived_at' => null,
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Actions\Issues\ArchiveIssue;
use App\Actions\Issues\ArchiveCompletedIssues;
use App\Actions\Issues\CreateIssue;
use App\Actions\Issues\ListIssues;
use App\Actions\Issues\SetIssueParent;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Mcp\Servers\TaskrServer;
use App\Mcp\Tools\ListIssues as ListIssuesTool;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

class IssueArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TestResponse::macro('result', function (): array {
            /** @var TestResponse $this */
            return $this->response->toArray()['result'] ?? [];
        });

        TestResponse::macro('payload', function (): array {
            /** @var TestResponse $this */
            return $this->response->toArray()['result']['structuredContent'] ?? [];
        });
    }

    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);

        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        return [$owner, $workspace, $project, $member];
    }

    private function doneStatus($workspace)
    {
        return $workspace->statuses()->where('category', 'done')->first();
    }

    private function cancelledStatus($workspace)
    {
        return $workspace->statuses()->where('category', 'cancelled')->first();
    }

    private function backlogStatus($workspace)
    {
        return $workspace->statuses()->where('category', 'backlog')->first();
    }

    public function test_fresh_issue_is_unarchived(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Active']);

        $this->assertFalse($issue->isArchived());
        $this->assertTrue(Issue::unarchived()->whereKey($issue->id)->exists());
        $this->assertFalse(Issue::archived()->whereKey($issue->id)->exists());
    }

    public function test_owner_archives_a_done_issue(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Finished', 'status_id' => $this->doneStatus($workspace)->id]);

        $this->actingAs($owner)
            ->post("/issues/{$issue->id}/archive")
            ->assertRedirect();

        $this->assertNotNull($issue->fresh()->archived_at);
    }

    public function test_member_cannot_archive(): void
    {
        [$owner, $workspace, $project, $member] = $this->setupWorkspace();

        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Finished', 'status_id' => $this->doneStatus($workspace)->id]);

        $this->actingAs($member)
            ->post("/issues/{$issue->id}/archive")
            ->assertForbidden();

        $this->assertNull($issue->fresh()->archived_at);
    }

    public function test_open_issue_cannot_be_archived(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Open']);

        $this->actingAs($owner)
            ->post("/issues/{$issue->id}/archive")
            ->assertStatus(409);

        $this->assertNull($issue->fresh()->archived_at);
    }

    public function test_cancelled_issue_is_archivable(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Abandoned', 'status_id' => $this->cancelledStatus($workspace)->id]);

        ArchiveIssue::run($issue);

        $this->assertNotNull($issue->fresh()->archived_at);
    }

    public function test_owner_unarchives(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Finished', 'status_id' => $this->doneStatus($workspace)->id]);
        ArchiveIssue::run($issue);

        $this->actingAs($owner)
            ->post("/issues/{$issue->id}/archive")
            ->assertRedirect();

        $this->assertNull($issue->fresh()->archived_at);
    }

    public function test_archiving_a_parent_cascades_to_children(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $parent = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Epic', 'status_id' => $this->doneStatus($workspace)->id]);
        $activeChild = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Child', 'status_id' => $this->backlogStatus($workspace)->id]);
        SetIssueParent::run($activeChild, ['parent_id' => $parent->id]);

        ArchiveIssue::run($parent);

        $this->assertNotNull($activeChild->fresh()->archived_at);

        ArchiveIssue::run($parent->fresh());

        $this->assertNull($activeChild->fresh()->archived_at);
    }

    public function test_cross_workspace_issue_is_not_findable(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Finished', 'status_id' => $this->doneStatus($workspace)->id]);
        ArchiveIssue::run($issue);

        $intruder = User::factory()->create();
        CreateWorkspace::run($intruder, ['name' => 'Theirs']);
        app(WorkspaceContext::class)->set($workspace);

        $this->actingAs($intruder)
            ->post("/issues/{$issue->id}/archive")
            ->assertForbidden();

        $this->assertNotNull($issue->fresh()->archived_at);
    }

    public function test_board_excludes_archived_issues(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Finished', 'status_id' => $this->doneStatus($workspace)->id]);
        ArchiveIssue::run($issue);

        $response = $this->actingAs($owner)->get("/projects/{$project->id}/board");

        $response->assertOk();
        $this->assertStringNotContainsString('"title":"Finished"', $response->getContent());
    }

    public function test_archived_issue_page_still_renders(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Finished', 'status_id' => $this->doneStatus($workspace)->id]);
        ArchiveIssue::run($issue);

        $this->actingAs($owner)
            ->get("/issues/{$issue->id}")
            ->assertOk();
    }

    public function test_list_issues_excludes_archived_by_default(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $active = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Active']);
        $archived = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Archived', 'status_id' => $this->doneStatus($workspace)->id]);
        ArchiveIssue::run($archived);

        $result = ListIssues::run($workspace, []);

        $this->assertSame([$active->id], array_column($result['issues'], 'id'));
        $this->assertSame(1, $result['total']);
    }

    public function test_list_issues_archived_include_and_only(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $active = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Active']);
        $archived = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Archived', 'status_id' => $this->doneStatus($workspace)->id]);
        ArchiveIssue::run($archived);

        $include = ListIssues::run($workspace, ['archived' => 'include']);
        $this->assertSame(2, $include['total']);

        $only = ListIssues::run($workspace, ['archived' => 'only']);
        $this->assertSame([$archived->id], array_column($only['issues'], 'id'));
    }

    public function test_project_archives_page_lists_only_archived(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $active = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Active']);
        $archived = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Archived', 'status_id' => $this->doneStatus($workspace)->id]);
        ArchiveIssue::run($archived);

        $response = $this->actingAs($owner)->get("/projects/{$project->id}/archives");

        $response->assertOk();
        $this->assertStringContainsString($archived->title, $response->getContent());
        $this->assertStringNotContainsString('"title":"Active"', $response->getContent());
    }

    public function test_mass_archive_targets_done_and_cancelled_only(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $done = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Done', 'status_id' => $this->doneStatus($workspace)->id]);
        $cancelled = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Cancelled', 'status_id' => $this->cancelledStatus($workspace)->id]);
        $open = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Open']);

        $count = ArchiveCompletedIssues::run($project, $owner);

        $this->assertSame(2, $count);
        $this->assertNotNull($done->fresh()->archived_at);
        $this->assertNotNull($cancelled->fresh()->archived_at);
        $this->assertNull($open->fresh()->archived_at);
    }

    public function test_member_cannot_mass_archive(): void
    {
        [$owner, $workspace, $project, $member] = $this->setupWorkspace();

        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Done', 'status_id' => $this->doneStatus($workspace)->id]);

        $this->actingAs($member)
            ->post("/projects/{$project->id}/archive-completed")
            ->assertForbidden();
    }

    public function test_mass_archive_empty_project_archives_nothing(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Open']);

        $count = ArchiveCompletedIssues::run($project, $owner);

        $this->assertSame(0, $count);
    }

    public function test_health_excludes_archived_issues(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Done', 'status_id' => $this->doneStatus($workspace)->id]);

        $before = \App\Actions\Projects\GetProjectHealth::run($project, $owner);
        ArchiveIssue::run($issue);
        $after = \App\Actions\Projects\GetProjectHealth::run($project, $owner);

        $this->assertSame(1, $before->issuesByCategory['done']);
        $this->assertSame(0, $after->issuesByCategory['done']);
        $this->assertSame(0, $after->totalIssues);
    }

    public function test_mcp_list_issues_archived_param(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $active = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Active']);
        $archived = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Archived', 'status_id' => $this->doneStatus($workspace)->id]);
        ArchiveIssue::run($archived);

        $default = TaskrServer::actingAs($owner)
            ->tool(ListIssuesTool::class, ['workspace_id' => $workspace->id])
            ->assertOk()
            ->payload();
        $this->assertSame([$active->id], array_column($default['issues'], 'id'));

        $only = TaskrServer::actingAs($owner)
            ->tool(ListIssuesTool::class, ['workspace_id' => $workspace->id, 'archived' => 'only'])
            ->assertOk()
            ->payload();
        $this->assertSame([$archived->id], array_column($only['issues'], 'id'));

        $include = TaskrServer::actingAs($owner)
            ->tool(ListIssuesTool::class, ['workspace_id' => $workspace->id, 'archived' => 'include'])
            ->assertOk()
            ->payload();
        $this->assertCount(2, $include['issues']);
    }
}

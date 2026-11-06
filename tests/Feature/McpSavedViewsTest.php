<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Labels\CreateLabel;
use App\Actions\Projects\CreateProject;
use App\Actions\SavedViews\CreateSavedView;
use App\Actions\Workspaces\CreateWorkspace;
use App\Mcp\Servers\TaskrServer;
use App\Mcp\Tools\ListSavedViewIssues;
use App\Mcp\Tools\ListSavedViews;
use App\Models\Membership;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

class McpSavedViewsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_list_saved_views_returns_workspace_views_alphabetically(): void
    {
        [$owner, $workspace, , $member] = $this->setupWorkspace();

        $bug = CreateLabel::run($workspace, ['name' => 'bug']);

        CreateSavedView::run($workspace, $member, ['name' => 'Zeta', 'filters' => ['priority' => 'high'], 'sort' => null, 'dir' => null]);
        CreateSavedView::run($workspace, $member, ['name' => 'Alpha', 'filters' => ['label' => $bug->id, 'q' => 'paiements'], 'sort' => 'priority', 'dir' => 'desc']);

        $result = TaskrServer::actingAs($owner)
            ->tool(ListSavedViews::class, ['workspace_id' => $workspace->id])
            ->assertOk()
            ->payload();

        $this->assertSame(['Alpha', 'Zeta'], array_column($result['views'], 'name'));

        $alpha = $result['views'][0];
        $this->assertSame(['label' => $bug->id, 'q' => 'paiements'], $alpha['filters']);
        $this->assertSame('priority', $alpha['sort']);
        $this->assertSame('desc', $alpha['dir']);
        $this->assertSame($member->id, $alpha['createdBy']);
        $this->assertIsString($alpha['summary']);
    }

    public function test_list_saved_views_stays_scoped_to_the_current_workspace(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        CreateSavedView::run($workspace, $owner, ['name' => 'Mine', 'filters' => [], 'sort' => null, 'dir' => null]);

        $other = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($other, ['name' => 'Other']);
        app(WorkspaceContext::class)->set($otherWorkspace);
        CreateSavedView::run($otherWorkspace, $other, ['name' => 'Theirs', 'filters' => [], 'sort' => null, 'dir' => null]);

        $result = TaskrServer::actingAs($owner)
            ->tool(ListSavedViews::class, ['workspace_id' => $workspace->id])
            ->assertOk()
            ->payload();

        $this->assertSame(['Mine'], array_column($result['views'], 'name'));
    }

    public function test_list_saved_view_issues_applies_the_view_filters_and_sort(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $first = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Paiements carte', 'priority' => 'urgent']);
        $second = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Paiements virement', 'priority' => 'urgent']);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Paiements hors sujet', 'priority' => 'low']);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Urgent sans rapport', 'priority' => 'urgent']);

        $first->forceFill(['created_at' => '2026-01-01 10:00:00'])->save();
        $second->forceFill(['created_at' => '2026-01-02 10:00:00'])->save();

        // The view keeps the urgent "Paiements" search, oldest first — the
        // reverse of the product's default order, so the stored sort is
        // observably applied.
        $view = CreateSavedView::run($workspace, $owner, [
            'name' => 'Paiements urgents',
            'filters' => ['priority' => 'urgent', 'q' => 'Paiements'],
            'sort' => 'created',
            'dir' => 'asc',
        ]);

        $result = TaskrServer::actingAs($owner)
            ->tool(ListSavedViewIssues::class, ['workspace_id' => $workspace->id, 'view_id' => $view->id])
            ->assertOk()
            ->payload();

        $this->assertSame(['Paiements carte', 'Paiements virement'], array_column($result['issues'], 'title'));
        $this->assertSame(2, $result['total']);
    }

    public function test_list_saved_view_issues_unknown_or_cross_workspace_view_is_not_found(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $response = TaskrServer::actingAs($owner)
            ->tool(ListSavedViewIssues::class, ['workspace_id' => $workspace->id, 'view_id' => (string) str()->ulid()]);

        $this->assertSame('not_found', $response->payload()['error']);

        // A view of another workspace does not exist here: same answer,
        // no existence leak.
        $other = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($other, ['name' => 'Other']);
        app(WorkspaceContext::class)->set($otherWorkspace);
        $theirView = CreateSavedView::run($otherWorkspace, $other, ['name' => 'Leur vue', 'filters' => [], 'sort' => null, 'dir' => null]);

        $response = TaskrServer::actingAs($owner)
            ->tool(ListSavedViewIssues::class, ['workspace_id' => $workspace->id, 'view_id' => $theirView->id]);

        $this->assertSame('not_found', $response->payload()['error']);
    }

    public function test_saved_views_tools_refuse_non_members_without_leak(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        CreateSavedView::run($workspace, $owner, ['name' => 'Secrète', 'filters' => [], 'sort' => null, 'dir' => null]);

        $intruder = User::factory()->create();
        CreateWorkspace::run($intruder, ['name' => 'Theirs']);

        TaskrServer::actingAs($intruder)
            ->tool(ListSavedViews::class, ['workspace_id' => $workspace->id])
            ->assertHasErrors(['You are not a member of this workspace.'])
            ->assertDontSee('Secrète');

        TaskrServer::actingAs($intruder)
            ->tool(ListSavedViewIssues::class, ['workspace_id' => $workspace->id, 'view_id' => (string) str()->ulid()])
            ->assertHasErrors(['You are not a member of this workspace.']);
    }
}

<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\StatusCategory;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ProjectArchivesFilterTest extends TestCase
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

    private function memberOf(Workspace $workspace): User
    {
        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        return $member;
    }

    public function test_archives_view_paginates_archived_issues(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        for ($i = 0; $i < 25; $i++) {
            $issue = $this->createIssue($workspace, $project, StatusCategory::Done);
            $issue->update(['archived_at' => now()->subMinutes(25 - $i)]);
        }

        $this->actingAs($owner)
            ->get("/projects/{$project->id}/archives")
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('issues', 20)
                    ->where('pagination.currentPage', 1)
                    ->where('pagination.lastPage', 2)
                    ->where('pagination.perPage', 20)
                    ->where('pagination.total', 25)
                    ->where('pagination.from', 1)
                    ->where('pagination.to', 20)
            );

        $this->actingAs($owner)
            ->get("/projects/{$project->id}/archives?page=2")
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('issues', 5)
                    ->where('pagination.currentPage', 2)
                    ->where('pagination.lastPage', 2)
                    ->where('pagination.perPage', 20)
                    ->where('pagination.total', 25)
                    ->where('pagination.from', 21)
                    ->where('pagination.to', 25)
            );
    }

    public function test_archives_view_filters_by_status(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $doneStatus = $this->statusOf($workspace, StatusCategory::Done);

        $done = $this->createIssue($workspace, $project, StatusCategory::Done);
        $done->update(['archived_at' => now()]);
        $cancelled = $this->createIssue($workspace, $project, StatusCategory::Cancelled);
        $cancelled->update(['archived_at' => now()]);

        $this->actingAs($owner)
            ->get("/projects/{$project->id}/archives?status={$doneStatus->id}")
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('issues', 1)
                    ->where('issues.0.id', $done->id)
                    ->where('pagination.total', 1)
            );
    }

    public function test_archives_view_filters_by_priority(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $high = $this->createIssue($workspace, $project, StatusCategory::Done, ['priority' => 'high']);
        $high->update(['archived_at' => now()]);
        $low = $this->createIssue($workspace, $project, StatusCategory::Done, ['priority' => 'low']);
        $low->update(['archived_at' => now()]);

        $this->actingAs($owner)
            ->get("/projects/{$project->id}/archives?priority=high")
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('issues', 1)
                    ->where('issues.0.id', $high->id)
                    ->where('pagination.total', 1)
            );
    }

    public function test_archives_view_filters_by_assignee(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $member = $this->memberOf($workspace);

        $assigned = $this->createIssue($workspace, $project, StatusCategory::Done);
        $assigned->assignees()->attach($member);
        $assigned->update(['archived_at' => now()]);
        $unassigned = $this->createIssue($workspace, $project, StatusCategory::Done);
        $unassigned->update(['archived_at' => now()]);

        $this->actingAs($owner)
            ->get("/projects/{$project->id}/archives?assignee={$member->id}")
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('issues', 1)
                    ->where('issues.0.id', $assigned->id)
                    ->where('pagination.total', 1)
            );
    }

    public function test_archives_view_filters_by_archived_date_range(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $before = $this->createIssue($workspace, $project, StatusCategory::Done);
        $before->update(['archived_at' => '2026-10-15 10:00:00']);
        $inRange = $this->createIssue($workspace, $project, StatusCategory::Done);
        $inRange->update(['archived_at' => '2026-10-22 10:00:00']);
        $after = $this->createIssue($workspace, $project, StatusCategory::Done);
        $after->update(['archived_at' => '2026-10-28 10:00:00']);

        $this->actingAs($owner)
            ->get("/projects/{$project->id}/archives?archived_from=2026-10-20&archived_to=2026-10-25")
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('issues', 1)
                    ->where('issues.0.id', $inRange->id)
                    ->where('pagination.total', 1)
            );
    }

    public function test_archives_view_filters_compose(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $doneStatus = $this->statusOf($workspace, StatusCategory::Done);

        $doneHigh = $this->createIssue($workspace, $project, StatusCategory::Done, ['priority' => 'high']);
        $doneHigh->update(['archived_at' => now()]);
        $doneLow = $this->createIssue($workspace, $project, StatusCategory::Done, ['priority' => 'low']);
        $doneLow->update(['archived_at' => now()]);
        $cancelledHigh = $this->createIssue($workspace, $project, StatusCategory::Cancelled, ['priority' => 'high']);
        $cancelledHigh->update(['archived_at' => now()]);

        $this->actingAs($owner)
            ->get("/projects/{$project->id}/archives?status={$doneStatus->id}&priority=high")
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('issues', 1)
                    ->where('issues.0.id', $doneHigh->id)
                    ->where('pagination.total', 1)
            );
    }

    public function test_archives_view_returns_filter_options(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->get("/projects/{$project->id}/archives")
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('statuses', 4)
                    ->has('statuses.0', fn (AssertableInertia $status) => $status
                        ->has('id')
                        ->has('name')
                        ->has('category')
                    )
                    ->has('members', 1)
                    ->has('members.0', fn (AssertableInertia $member) => $member
                        ->has('id')
                        ->has('name')
                    )
            );
    }

    public function test_archives_view_returns_active_filters(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $doneStatus = $this->statusOf($workspace, StatusCategory::Done);

        $this->actingAs($owner)
            ->get("/projects/{$project->id}/archives?status={$doneStatus->id}&priority=high&assignee=42&archived_from=2026-10-20&archived_to=2026-10-25")
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->where('filters.status', $doneStatus->id)
                    ->where('filters.priority', 'high')
                    ->where('filters.assignee', '42')
                    ->where('filters.archivedFrom', '2026-10-20')
                    ->where('filters.archivedTo', '2026-10-25')
            );
    }

    public function test_archives_view_returns_empty_filters_by_default(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->get("/projects/{$project->id}/archives")
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->where('filters.status', '')
                    ->where('filters.priority', '')
                    ->where('filters.assignee', '')
                    ->where('filters.archivedFrom', '')
                    ->where('filters.archivedTo', '')
            );
    }

    public function test_archives_view_ignores_invalid_priority_filter(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $issue = $this->createIssue($workspace, $project, StatusCategory::Done);
        $issue->update(['archived_at' => now()]);

        $this->actingAs($owner)
            ->get("/projects/{$project->id}/archives?priority=invalid")
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('issues', 1)
                    ->where('issues.0.id', $issue->id)
            );
    }
}

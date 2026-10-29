<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Issues\ListIssues;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Presenters\Issues\IssuePresenter;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class IssueDependencyPresenterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace, 2: Project, 3: array<string, Status>}
     */
    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);

        $statuses = [];
        foreach ($workspace->statuses as $status) {
            $statuses[$status->category->value] = $status;
        }

        return [$owner, $workspace, $project, $statuses];
    }

    public function test_detail_presents_blocked_by_and_blocks(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspace();

        $issue = fn (string $title, Status $status) => CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => $title,
            'status_id' => $status->id,
        ]);

        $a = $issue('A', $statuses['backlog']);
        $b = $issue('B', $statuses['in_progress']);
        $c = $issue('C', $statuses['done']);
        $a->blockedBy()->sync([$b->id, $c->id]);

        $a->load(['blockedBy.status', 'blocks.status']);
        $detail = IssuePresenter::detail($a);

        $blockedBy = collect($detail['blockedBy'])->sortBy('title')->values()->all();
        $this->assertSame([
            ['id' => $b->id, 'title' => 'B', 'statusName' => 'In Progress', 'statusCategory' => 'in_progress'],
            ['id' => $c->id, 'title' => 'C', 'statusName' => 'Done', 'statusCategory' => 'done'],
        ], $blockedBy);
        $this->assertSame([], $detail['blocks']);
        $this->assertTrue($detail['isBlocked']);
        $this->assertFalse($detail['isUnblockable']);

        // Mirror view: B sees A in its "blocks" list.
        $b->load(['blockedBy.status', 'blocks.status']);
        $mirror = IssuePresenter::detail($b);
        $this->assertSame([], $mirror['blockedBy']);
        $this->assertSame(
            [['id' => $a->id, 'title' => 'A', 'statusName' => 'Backlog', 'statusCategory' => 'backlog']],
            $mirror['blocks'],
        );
    }

    public function test_is_blocked_and_is_unblockable_states(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspace();

        $make = fn (string $title, ?Status $status = null) => CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => $title,
            ...($status === null ? [] : ['status_id' => $status->id]),
        ]);

        // No blocker.
        $free = $make('Free');

        // One open blocker.
        $blocked = $make('Blocked');
        $blocked->blockedBy()->sync([$make('Open blocker', $statuses['backlog'])->id]);

        // Every blocker done.
        $unblockable = $make('Unblockable');
        $unblockable->blockedBy()->sync([
            $make('Done blocker', $statuses['done'])->id,
            $make('Second done blocker', $statuses['done'])->id,
        ]);

        // Every blocker cancelled: a cancelled blocker no longer blocks.
        $cancelledOnly = $make('Cancelled only');
        $cancelledOnly->blockedBy()->sync([$make('Cancelled blocker', $statuses['cancelled'])->id]);

        // Mixed: one done + one open stays blocked.
        $mixed = $make('Mixed');
        $mixed->blockedBy()->sync([
            $make('Done blocker', $statuses['done'])->id,
            $make('Open blocker', $statuses['backlog'])->id,
        ]);

        $states = function (Issue $issue): array {
            $issue->load(['blockedBy.status', 'blocks.status']);
            $detail = IssuePresenter::detail($issue);

            return [$detail['isBlocked'], $detail['isUnblockable']];
        };

        $this->assertSame([false, false], $states($free));
        $this->assertSame([true, false], $states($blocked));
        $this->assertSame([false, true], $states($unblockable));
        $this->assertSame([false, true], $states($cancelledOnly));
        $this->assertSame([true, false], $states($mixed));
    }

    public function test_card_variants_carry_blocked_flag(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspace();

        $make = fn (string $title, ?Status $status = null) => CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => $title,
            ...($status === null ? [] : ['status_id' => $status->id]),
        ]);

        $blocked = $make('Blocked');
        $blocked->blockedBy()->sync([$make('Open blocker', $statuses['backlog'])->id]);
        $free = $make('Free');

        $blocked->load(['project', 'status', 'labels', 'assignees', 'blockedBy.status']);
        $free->load(['project', 'status', 'labels', 'assignees', 'blockedBy.status']);

        foreach ([$blocked, $free] as $index => $issue) {
            $expected = $index === 0;

            $card = IssuePresenter::card($issue);
            $this->assertSame($expected, $card['blocked']);
            $this->assertSame($expected, IssuePresenter::kanbanCard($card)['blocked']);
            $this->assertSame($expected, IssuePresenter::boardCard($issue)['blocked']);
        }
    }

    public function test_list_issues_blocked_filter(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspace();

        $make = fn (string $title, array $extra = []) => CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => $title,
            'status_id' => $statuses['backlog']->id,
            ...$extra,
        ]);

        $a = $make('A', ['priority' => 'high']);
        $a->blockedBy()->sync([$make('Open blocker', ['status_id' => $statuses['backlog']->id])->id]);

        // Done and cancelled blockers do not count as blocking.
        $c = $make('C', ['priority' => 'high']);
        $c->blockedBy()->sync([$make('Done blocker', ['status_id' => $statuses['done']->id])->id]);

        $e = $make('E', ['priority' => 'high']);
        $e->blockedBy()->sync([$make('Cancelled blocker', ['status_id' => $statuses['cancelled']->id])->id]);

        $g = $make('G', ['priority' => 'high']);

        $titles = fn (array $issues) => collect($issues)->pluck('title')->sort()->values()->all();

        $result = ListIssues::run($workspace, ['blocked' => '1']);
        $this->assertSame(['A'], $titles($result['issues']));
        $this->assertSame(1, $result['total']);

        // Composable with priority and status.
        $this->assertSame(['A'], $titles(ListIssues::run($workspace, ['blocked' => '1', 'priority' => 'high'])['issues']));
        $this->assertSame(['A', 'C', 'E', 'G'], $titles(ListIssues::run($workspace, ['priority' => 'high'])['issues']));
        $this->assertSame(
            ['A'],
            $titles(ListIssues::run($workspace, ['blocked' => '1', 'status' => $statuses['backlog']->id])['issues']),
        );
        $this->assertSame(
            ['A'],
            $titles(ListIssues::run($workspace, ['blocked' => 'true', 'status' => $statuses['backlog']->id])['issues']),
        );
    }

    public function test_show_issues_page_forwards_blocked_param(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspace();

        $a = CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'A',
            'status_id' => $statuses['backlog']->id,
        ]);
        $a->blockedBy()->sync([CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'Open blocker',
            'status_id' => $statuses['in_progress']->id,
        ])->id]);

        CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'Free',
            'status_id' => $statuses['backlog']->id,
        ]);

        $this->actingAs($owner)
            ->get('/issues?blocked=1')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Issues/Index')
                ->where('filters.blocked', '1')
                ->has('issues', 1)
                ->where('issues.0.title', 'A')
                ->where('issues.0.blocked', true));
    }
}

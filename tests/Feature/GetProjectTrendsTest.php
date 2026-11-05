<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Issues\MoveIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\Projects\GetProjectTrends;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ProjectTrends;
use App\Support\WorkspaceContext;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GetProjectTrendsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private Project $project;

    /** @var array<string, Status> */
    private array $statuses;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = CreateWorkspace::run($this->owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($this->workspace);
        $this->project = CreateProject::run($this->workspace, $this->owner, ['name' => 'Site vitrine']);

        $this->statuses = [];
        foreach ($this->workspace->statuses as $status) {
            $this->statuses[$status->category->value] = $status;
        }
    }

    /**
     * Create an issue with a backdated creation date.
     */
    private function createIssue(string $category, string $title = 'Issue', ?CarbonInterface $createdAt = null, ?Workspace $workspace = null, ?Project $project = null): Issue
    {
        $issue = CreateIssue::run($workspace ?? $this->workspace, [
            'project_id' => ($project ?? $this->project)->id,
            'title' => $title,
            'status_id' => $this->statuses[$category]->id,
        ]);

        if ($createdAt !== null) {
            DB::table('issues')->where('id', $issue->id)->update([
                'created_at' => $createdAt->toDateTimeString(),
            ]);
        }

        return $issue;
    }

    /**
     * Move an issue to a status category and backdate the journalled event.
     */
    private function moveIssueOn(Issue $issue, string $toCategory, CarbonInterface $onDate): void
    {
        MoveIssue::run($issue, $this->statuses[$toCategory]->id);

        DB::table('audit_events')
            ->where('issue_id', $issue->id)
            ->where('type', 'issue.status_changed')
            ->orderByDesc('created_at')
            ->limit(1)
            ->update(['created_at' => $onDate->toDateTimeString()]);
    }

    /**
     * Index the series points by their day.
     *
     * @param  array<int, array{date: string, opened: int, closed: int, moved: int}>  $points
     * @return Collection<string, array{date: string, opened: int, closed: int, moved: int}>
     */
    private function byDate(array $points): Collection
    {
        return collect($points)->keyBy('date');
    }

    // ── Opened series ────────────────────────────────────────────────

    public function test_trends_opened_counts_issue_creation_per_day_including_archived(): void
    {
        $this->createIssue('backlog', 'A', now()->subDays(2));
        $this->createIssue('backlog', 'B', now()->subDays(2));
        $this->createIssue('backlog', 'C', now()->subDay());
        $archived = $this->createIssue('backlog', 'D', now());
        DB::table('issues')->where('id', $archived->id)->update(['archived_at' => now()]);

        $trends = GetProjectTrends::run($this->project, '30');

        $byDate = $this->byDate($trends->points);
        $this->assertSame(2, $byDate->get(now()->subDays(2)->toDateString())['opened']);
        $this->assertSame(1, $byDate->get(now()->subDay()->toDateString())['opened']);
        // Archived issues are historical facts: they stay in the series.
        $this->assertSame(1, $byDate->get(now()->toDateString())['opened']);
    }

    // ── Closed & moved series (audit journal) ────────────────────────

    public function test_trends_closed_counts_status_changes_to_done_per_day(): void
    {
        $closed = $this->createIssue('backlog', 'A', now()->subDays(3));
        $this->createIssue('backlog', 'B', now()->subDays(3));

        $this->moveIssueOn($closed, 'done', now()->subDay());

        $trends = GetProjectTrends::run($this->project, '30');

        $byDate = $this->byDate($trends->points);
        $this->assertSame(1, $byDate->get(now()->subDay()->toDateString())['closed']);
        $this->assertSame(0, $byDate->get(now()->toDateString())['closed']);
    }

    public function test_trends_moved_counts_all_status_changes_per_day(): void
    {
        $issue = $this->createIssue('backlog', 'A', now()->subDays(5));

        $this->moveIssueOn($issue, 'in_progress', now()->subDays(2));
        $this->moveIssueOn($issue, 'done', now()->subDay());

        $trends = GetProjectTrends::run($this->project, '30');

        $byDate = $this->byDate($trends->points);
        // Any status change is a move, including the one towards done.
        $this->assertSame(1, $byDate->get(now()->subDays(2)->toDateString())['moved']);
        $this->assertSame(1, $byDate->get(now()->subDay()->toDateString())['moved']);
        // Only the move towards a done-category status is a closure.
        $this->assertSame(0, $byDate->get(now()->subDays(2)->toDateString())['closed']);
        $this->assertSame(1, $byDate->get(now()->subDay()->toDateString())['closed']);
    }

    public function test_trends_journal_since_is_null_without_status_changes(): void
    {
        $this->createIssue('backlog', 'A', now());

        $trends = GetProjectTrends::run($this->project, '30');

        $this->assertNull($trends->journalSince);
        $this->assertSame(0, collect($trends->points)->sum('moved'));
        $this->assertSame(0, collect($trends->points)->sum('closed'));
    }

    public function test_trends_report_the_first_journalled_status_change_date(): void
    {
        $first = $this->createIssue('backlog', 'A', now()->subDays(5));
        $second = $this->createIssue('backlog', 'B', now()->subDays(3));

        $this->moveIssueOn($first, 'in_progress', now()->subDays(3));
        $this->moveIssueOn($second, 'in_progress', now()->subDay());

        $trends = GetProjectTrends::run($this->project, '30');

        $this->assertSame(now()->subDays(3)->toDateString(), $trends->journalSince);
    }

    // ── Period windows ───────────────────────────────────────────────

    public function test_trends_period_30_buckets_the_last_thirty_days(): void
    {
        $this->createIssue('backlog', 'Too old', now()->subDays(40));
        $this->createIssue('backlog', 'In window', now()->subDays(10));

        $trends = GetProjectTrends::run($this->project, '30');

        $this->assertSame('30', $trends->period);
        $this->assertCount(30, $trends->points);
        $this->assertSame(now()->subDays(29)->toDateString(), $trends->points[0]['date']);
        $this->assertSame(now()->toDateString(), $trends->points[29]['date']);
        $this->assertSame(1, collect($trends->points)->sum('opened'));
    }

    public function test_trends_period_90_buckets_the_last_ninety_days(): void
    {
        $this->createIssue('backlog', 'In window', now()->subDays(60));

        $trends = GetProjectTrends::run($this->project, '90');

        $this->assertSame('90', $trends->period);
        $this->assertCount(90, $trends->points);
        $this->assertSame(now()->subDays(89)->toDateString(), $trends->points[0]['date']);
        $this->assertSame(1, collect($trends->points)->sum('opened'));
    }

    public function test_trends_period_all_starts_at_project_creation(): void
    {
        DB::table('projects')->where('id', $this->project->id)->update([
            'created_at' => now()->subDays(2)->toDateTimeString(),
        ]);
        $this->project = $this->project->fresh();

        $this->createIssue('backlog', 'A', now()->subDays(2));
        $this->createIssue('backlog', 'B', now());

        $trends = GetProjectTrends::run($this->project, 'all');

        $this->assertSame('all', $trends->period);
        $this->assertCount(3, $trends->points);
        $this->assertSame(now()->subDays(2)->toDateString(), $trends->points[0]['date']);
        $this->assertSame(now()->toDateString(), $trends->points[2]['date']);
        $this->assertSame(1, $trends->points[0]['opened']);
        $this->assertSame(0, $trends->points[1]['opened']);
        $this->assertSame(1, $trends->points[2]['opened']);
    }

    // ── Isolation ────────────────────────────────────────────────────

    public function test_trends_exclude_other_projects_of_the_workspace(): void
    {
        $other = CreateProject::run($this->workspace, $this->owner, ['name' => 'Other project']);
        $this->createIssue('backlog', 'Mine', now());
        $theirs = $this->createIssue('backlog', 'Theirs', now(), project: $other);

        $this->moveIssueOn($theirs, 'done', now());

        $trends = GetProjectTrends::run($this->project, '30');

        $this->assertSame(1, collect($trends->points)->sum('opened'));
        $this->assertSame(0, collect($trends->points)->sum('moved'));
        $this->assertSame(0, collect($trends->points)->sum('closed'));
    }

    public function test_trends_ignore_another_workspaces_project(): void
    {
        $intruder = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($intruder, ['name' => 'Theirs']);
        $otherProject = CreateProject::run($otherWorkspace, $intruder, ['name' => 'Their project']);
        $theirStatuses = [];
        foreach (Status::withoutWorkspaceScope()->where('workspace_id', $otherWorkspace->id)->get() as $status) {
            $theirStatuses[$status->category->value] = $status;
        }

        $theirIssue = CreateIssue::run($otherWorkspace, [
            'project_id' => $otherProject->id,
            'title' => 'Foreign issue',
            'status_id' => $theirStatuses['backlog']->id,
        ]);
        MoveIssue::run($theirIssue, $theirStatuses['done']->id);

        $this->createIssue('backlog', 'Mine', now());

        $trends = GetProjectTrends::run($this->project, '30');

        $this->assertSame(1, collect($trends->points)->sum('opened'));
        $this->assertSame(0, collect($trends->points)->sum('moved'));
        $this->assertSame(0, collect($trends->points)->sum('closed'));
    }

    // ── Shape ────────────────────────────────────────────────────────

    public function test_trends_returns_the_dto_payload_shape(): void
    {
        $this->createIssue('backlog', 'A', now());

        $trends = GetProjectTrends::run($this->project, '30');

        $this->assertInstanceOf(ProjectTrends::class, $trends);
        $payload = $trends->toArray();
        $this->assertSame('30', $payload['period']);
        $this->assertCount(30, $payload['points']);
        $this->assertSame(
            ['date', 'opened', 'closed', 'moved'],
            array_keys($payload['points'][0]),
        );
        $this->assertNull($payload['journalSince']);
    }
}

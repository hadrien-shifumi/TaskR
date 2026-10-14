<?php

namespace App\Actions\Projects;

use App\Enums\StatusCategory;
use App\Models\Project;
use App\Models\User;
use App\Responders\Projects\ProjectHealthResponder;
use App\Support\ProjectHealth;
use App\Support\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

class GetProjectHealth
{
    use AsController;
    use AsObject;

    public function authorize(): bool
    {
        $user = request()->user();

        return $user instanceof User && app(WorkspaceContext::class)->isMember($user);
    }

    /**
     * Compute health indicators for a single project.
     */
    public function handle(Project $project): ProjectHealth
    {
        $aggregates = $this->aggregateForProjects([$project->id])[$project->id]
            ?? $this->emptyAggregates();

        return $this->buildHealth($project, $aggregates);
    }

    /**
     * Compute health indicators for all given projects in a single grouped pass.
     *
     * @param  array<int, Project>  $projects
     * @return array<string, ProjectHealth> Keyed by project id.
     */
    public function handleForProjects(array $projects): array
    {
        if ($projects === []) {
            return [];
        }

        $projectIds = array_map(fn (Project $project) => $project->id, $projects);
        $aggregates = $this->aggregateForProjects($projectIds);

        $health = [];
        foreach ($projects as $project) {
            $health[$project->id] = $this->buildHealth(
                $project,
                $aggregates[$project->id] ?? $this->emptyAggregates(),
            );
        }

        return $health;
    }

    public function asController(Request $request, Project $project, ProjectHealthResponder $responder): Response
    {
        $health = $this->handle($project);

        return $responder->toResponse($request, [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'targetDate' => $project->target_date?->toDateString(),
                'createdAt' => $project->created_at?->toISOString(),
            ],
            'health' => $health->toArray(),
        ]);
    }

    /**
     * Run grouped aggregate queries for the given project ids.
     *
     * @param  array<int, string>  $projectIds
     * @return array<string, array<string, mixed>> Keyed by project id.
     */
    private function aggregateForProjects(array $projectIds): array
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        // Count issues per status category, plus overdue count.
        $issueCounts = $this->issuesBaseQuery($projectIds, $workspaceId)
            ->selectRaw('project_id')
            ->selectRaw('SUM(CASE WHEN statuses.category = ? THEN 1 ELSE 0 END) AS cnt_backlog', [StatusCategory::Backlog->value])
            ->selectRaw('SUM(CASE WHEN statuses.category = ? THEN 1 ELSE 0 END) AS cnt_in_progress', [StatusCategory::InProgress->value])
            ->selectRaw('SUM(CASE WHEN statuses.category = ? THEN 1 ELSE 0 END) AS cnt_done', [StatusCategory::Done->value])
            ->selectRaw('SUM(CASE WHEN statuses.category = ? THEN 1 ELSE 0 END) AS cnt_cancelled', [StatusCategory::Cancelled->value])
            ->selectRaw('COUNT(*) AS total_issues')
            ->selectRaw('SUM(CASE WHEN issues.due_date IS NOT NULL AND issues.due_date < ? AND statuses.category NOT IN (?, ?) THEN 1 ELSE 0 END) AS overdue_issues', [
                now()->toDateString(),
                StatusCategory::Done->value,
                StatusCategory::Cancelled->value,
            ])
            ->groupBy('project_id')
            ->get()
            ->keyBy('project_id');

        // Sum estimated hours per project.
        $estimates = $this->issuesBaseQuery($projectIds, $workspaceId)
            ->selectRaw('project_id, COALESCE(SUM(estimated_hours), 0) AS estimated_hours')
            ->groupBy('project_id')
            ->get()
            ->keyBy('project_id');

        // Sum logged hours per project (join time_entries).
        $logged = \DB::table('time_entries')
            ->join('issues', 'issues.id', '=', 'time_entries.issue_id')
            ->where('time_entries.workspace_id', $workspaceId)
            ->whereIn('issues.project_id', $projectIds)
            ->selectRaw('issues.project_id, COALESCE(SUM(time_entries.hours), 0) AS logged_hours')
            ->groupBy('issues.project_id')
            ->get()
            ->keyBy('project_id');

        $result = [];
        foreach ($projectIds as $projectId) {
            $counts = $issueCounts->get($projectId);
            $est = $estimates->get($projectId);
            $log = $logged->get($projectId);

            $result[$projectId] = [
                'backlog' => (int) ($counts->cnt_backlog ?? 0),
                'in_progress' => (int) ($counts->cnt_in_progress ?? 0),
                'done' => (int) ($counts->cnt_done ?? 0),
                'cancelled' => (int) ($counts->cnt_cancelled ?? 0),
                'total_issues' => (int) ($counts->total_issues ?? 0),
                'overdue_issues' => (int) ($counts->overdue_issues ?? 0),
                'estimated_hours' => (float) ($est->estimated_hours ?? 0),
                'logged_hours' => (float) ($log->logged_hours ?? 0),
            ];
        }

        return $result;
    }

    /**
     * Base query for issue aggregates: issues joined with statuses, scoped to projects.
     *
     * @param  array<int, string>  $projectIds
     */
    private function issuesBaseQuery(array $projectIds, ?string $workspaceId): Builder
    {
        return \DB::table('issues')
            ->join('statuses', 'statuses.id', '=', 'issues.status_id')
            ->where('issues.workspace_id', $workspaceId)
            ->whereIn('issues.project_id', $projectIds);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyAggregates(): array
    {
        return [
            'backlog' => 0,
            'in_progress' => 0,
            'done' => 0,
            'cancelled' => 0,
            'total_issues' => 0,
            'overdue_issues' => 0,
            'estimated_hours' => 0.0,
            'logged_hours' => 0.0,
        ];
    }

    /**
     * @param  array<string, mixed>  $aggregates
     */
    private function buildHealth(Project $project, array $aggregates): ProjectHealth
    {
        $totalIssues = $aggregates['total_issues'];
        $doneAndCancelled = $aggregates['done'] + $aggregates['cancelled'];

        $completionPercentage = $totalIssues > 0
            ? round($doneAndCancelled / $totalIssues * 100, 1)
            : null;

        $estimatedHours = $aggregates['estimated_hours'];
        $loggedHours = $aggregates['logged_hours'];

        $consumptionRatio = $estimatedHours > 0
            ? round($loggedHours / $estimatedHours * 100, 1)
            : null;

        // Projection: only when target_date is set.
        $expectedPercentage = null;
        $status = null;

        if ($project->target_date !== null) {
            $expectedPercentage = $this->computeExpectedPercentage($project);
            $status = $this->computeStatus($project, $completionPercentage, $expectedPercentage);
        }

        return new ProjectHealth(
            issuesByCategory: [
                'backlog' => $aggregates['backlog'],
                'in_progress' => $aggregates['in_progress'],
                'done' => $aggregates['done'],
                'cancelled' => $aggregates['cancelled'],
            ],
            totalIssues: $totalIssues,
            completionPercentage: $completionPercentage,
            overdueIssues: $aggregates['overdue_issues'],
            estimatedHours: $estimatedHours,
            loggedHours: $loggedHours,
            consumptionRatio: $consumptionRatio,
            expectedPercentage: $expectedPercentage,
            status: $status,
            targetDate: $project->target_date?->toDateString(),
        );
    }

    /**
     * ADR-3 formula: attendu = clamp((aujourdhui - created_at) / (target_date - created_at), 0, 1)
     */
    private function computeExpectedPercentage(Project $project): float
    {
        if ($project->target_date === null || $project->created_at === null) {
            return 0.0;
        }

        $createdAt = Carbon::parse($project->created_at)->startOfDay();
        $targetDate = Carbon::parse($project->target_date)->startOfDay();
        $today = now()->startOfDay();

        $totalDays = $createdAt->diffInDays($targetDate);

        if ($totalDays <= 0) {
            // target_date <= created_at: expected = 1 immediately (clamp).
            return 100.0;
        }

        $elapsedDays = $createdAt->diffInDays($today);
        $ratio = $elapsedDays / $totalDays;

        // Clamp to [0, 1].
        $ratio = max(0.0, min(1.0, $ratio));

        return round($ratio * 100, 1);
    }

    /**
     * ADR-3 status:
     * - late: target_date passed AND completion < 100%
     * - at-risk: completion < expected
     * - ok: otherwise
     *
     * Project without issues: status = ok.
     */
    private function computeStatus(Project $project, ?float $completionPercentage, float $expectedPercentage): string
    {
        // No issues: ok by convention.
        if ($completionPercentage === null) {
            return 'ok';
        }

        // Late: target_date is in the past and not 100% complete.
        if ($project->target_date !== null && now()->startOfDay()->gt(Carbon::parse($project->target_date)->startOfDay())) {
            if ($completionPercentage < 100.0) {
                return 'late';
            }

            return 'ok';
        }

        // At-risk: behind expected pace.
        if ($completionPercentage < $expectedPercentage) {
            return 'at-risk';
        }

        return 'ok';
    }
}

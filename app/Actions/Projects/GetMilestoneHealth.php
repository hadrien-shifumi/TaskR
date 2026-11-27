<?php

namespace App\Actions\Projects;

use App\Models\Milestone;
use App\Models\Project;
use App\Support\MilestoneHealth;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Per-milestone projection of a project's Health page: the expected
 * pace (linear from the start date to the target date, clamped) and
 * the real pace (done issues over unarchived attached issues) for
 * each milestone, plus a status badge and the late-panel flag.
 *
 * The "today" is injectable so tests can freeze the calendar; the
 * production caller never passes it (now() is used).
 */
class GetMilestoneHealth
{
    use AsObject;

    /**
     * @param  CarbonInterface|null  $today  Injected "today" — tests pass a frozen
     *                                       date, production callers pass nothing.
     */
    public function handle(Project $project, ?CarbonInterface $today = null): MilestoneHealth
    {
        $today = $today ?? Carbon::today();

        $milestones = $project->milestones()
            ->withIssueCounts()
            ->get();

        $rows = $milestones
            ->map(fn (Milestone $milestone) => $this->buildRow($milestone, $project, $today))
            ->sort(fn (array $a, array $b) => $this->compareRows($a, $b))
            ->values()
            ->all();

        return new MilestoneHealth($rows);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRow(Milestone $milestone, Project $project, CarbonInterface $today): array
    {
        $totalIssues = (int) $milestone->issues_count;
        $doneIssues = (int) $milestone->done_issues_count;

        $realPercentage = $totalIssues > 0
            ? round($doneIssues / $totalIssues * 100, 1)
            : null;

        $startDate = $milestone->start_date !== null
            ? Carbon::parse($milestone->start_date)->startOfDay()
            : ($project->created_at !== null ? Carbon::parse($project->created_at)->startOfDay() : null);

        $targetDate = $milestone->target_date !== null
            ? Carbon::parse($milestone->target_date)->startOfDay()
            : null;

        $expectedPercentage = null;
        if ($targetDate !== null && $startDate !== null) {
            $expectedPercentage = $this->computeExpectedPercentage($startDate, $targetDate, $today);
        }

        $status = null;
        $inLatePanel = false;

        if ($targetDate !== null && $totalIssues > 0) {
            if ($milestone->completed_at !== null) {
                // Closed: on time when the closure landed before the end
                // of the target day. A late closure is missed but never
                // enters the late panel.
                $completedAt = Carbon::parse($milestone->completed_at);
                if ($completedAt->lte($targetDate->copy()->endOfDay())) {
                    $status = 'achieved';
                } else {
                    $status = 'missed';
                }
            } elseif ($doneIssues === $totalIssues) {
                $status = 'achieved';
            } elseif ($targetDate->lt($today->startOfDay())) {
                // The target day itself is still on time: only a target
                // strictly before today makes the milestone missed.
                $status = 'missed';
                $inLatePanel = true;
            } else {
                $status = 'upcoming';
            }
        }

        return [
            'id' => $milestone->id,
            'title' => $milestone->title,
            'startDate' => $milestone->start_date?->toDateString(),
            'targetDate' => $milestone->target_date?->toDateString(),
            'completedAt' => $milestone->completed_at?->toISOString(),
            'totalIssues' => $totalIssues,
            'doneIssues' => $doneIssues,
            'realPercentage' => $realPercentage,
            'expectedPercentage' => $expectedPercentage,
            'status' => $status,
            'inLatePanel' => $inLatePanel,
        ];
    }

    /**
     * Linear pace from the start to the target date, clamped 0–100,
     * rounded to the tenth like the project indicator. A target on or
     * before the start means 100 % immediately.
     */
    private function computeExpectedPercentage(CarbonInterface $start, CarbonInterface $target, CarbonInterface $today): float
    {
        if ($target->lte($start)) {
            return 100.0;
        }

        $totalDays = $start->diffInDays($target);
        $elapsedDays = $start->diffInDays($today->startOfDay());

        $ratio = $totalDays > 0 ? $elapsedDays / $totalDays : 1.0;
        $ratio = max(0.0, min(1.0, $ratio));

        return round($ratio * 100, 1);
    }

    /**
     * Target date ascending (undated last), then title ascending.
     */
    private function compareRows(array $a, array $b): int
    {
        if ($a['targetDate'] === null && $b['targetDate'] === null) {
            return strcmp($a['title'], $b['title']);
        }

        if ($a['targetDate'] === null) {
            return 1;
        }

        if ($b['targetDate'] === null) {
            return -1;
        }

        $byTarget = strcmp($a['targetDate'], $b['targetDate']);

        return $byTarget !== 0 ? $byTarget : strcmp($a['title'], $b['title']);
    }
}

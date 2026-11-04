<?php

namespace App\Actions\Projects;

use App\Enums\AuditEventType;
use App\Enums\StatusCategory;
use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\Status;
use App\Support\ProjectTrends;
use App\Support\WorkspaceContext;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Daily trend series of a project: how many issues were opened (created),
 * closed (moved to a done-category status) and moved (any status change)
 * per day over the selected window.
 *
 * Opened reads the issue creation dates — archived issues included, they
 * are historical facts. Closed and moved read the audit journal, which
 * only exists since it was introduced: those two series start at its
 * first journalled event.
 */
class GetProjectTrends
{
    use AsObject;

    /**
     * @param  '30'|'90'|'all'  $period
     */
    public function handle(Project $project, string $period = '30'): ProjectTrends
    {
        $workspaceId = app(WorkspaceContext::class)->id();
        $start = $this->windowStart($project, $period);
        $today = Carbon::today();

        // Opened: issue creation dates, archived included (historical facts).
        $createdPerDay = \DB::table('issues')
            ->where('issues.workspace_id', $workspaceId)
            ->where('issues.project_id', $project->id)
            ->where('issues.created_at', '>=', $start)
            ->get(['created_at'])
            ->countBy(fn ($issue) => Carbon::parse($issue->created_at)->toDateString());

        // Closed & moved: the audit journal. It only dates back to its
        // introduction — events before that are simply not readable.
        $events = AuditEvent::query()
            ->where('project_id', $project->id)
            ->where('type', AuditEventType::IssueStatusChanged)
            ->where('created_at', '>=', $start)
            ->get(['changes', 'created_at']);

        $categoriesByStatusName = Status::query()
            ->where('workspace_id', $workspaceId)
            ->pluck('category', 'name');

        $movedPerDay = new Collection;
        $closedPerDay = new Collection;
        foreach ($events as $event) {
            $after = $event->changes['status']['after'] ?? null;
            if (! is_string($after) || $after === '') {
                continue;
            }

            $day = Carbon::parse($event->created_at)->toDateString();
            $movedPerDay->put($day, ($movedPerDay->get($day) ?? 0) + 1);

            $isClosure = ($categoriesByStatusName[$after] ?? null) === StatusCategory::Done;
            if ($isClosure) {
                $closedPerDay->put($day, ($closedPerDay->get($day) ?? 0) + 1);
            }
        }

        // One list entry per day of the window, ascending, zero-filled.
        $points = [];
        foreach ($this->dayBuckets($start, $today) as $day => $counts) {
            $points[] = [
                'date' => $day,
                'opened' => $createdPerDay->get($day, 0),
                'closed' => $closedPerDay->get($day, 0),
                'moved' => $movedPerDay->get($day, 0),
            ];
        }

        $journalSince = AuditEvent::query()
            ->where('project_id', $project->id)
            ->where('type', AuditEventType::IssueStatusChanged)
            ->min('created_at');

        return new ProjectTrends(
            period: $period,
            points: $points,
            journalSince: $journalSince !== null ? Carbon::parse($journalSince)->toDateString() : null,
        );
    }

    /**
     * First day of the window, zero-filled up to today.
     */
    private function windowStart(Project $project, string $period): CarbonInterface
    {
        if ($period === 'all') {
            return Carbon::parse($project->created_at)->startOfDay();
        }

        return Carbon::today()->subDays((int) $period - 1);
    }

    /**
     * Zero-filled day buckets from the window start to today.
     *
     * @return array<string, array{opened: int, closed: int, moved: int}>
     */
    private function dayBuckets(CarbonInterface $start, CarbonInterface $end): array
    {
        $buckets = [];
        for ($day = $start->copy(); $day->lte($end); $day = $day->addDay()) {
            $buckets[$day->toDateString()] = ['opened' => 0, 'closed' => 0, 'moved' => 0];
        }

        return $buckets;
    }
}

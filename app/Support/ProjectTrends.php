<?php

namespace App\Support;

/**
 * Pure data object holding the daily trend series of a project
 * (opened / closed / moved issues per day) for a selected period.
 *
 * Never persisted: always calculated at read time from issue creation
 * dates and the append-only audit journal. Archived issues count —
 * these series are historical facts, unlike the current-work health
 * indicators.
 */
class ProjectTrends
{
    /**
     * @param  '30'|'90'|'all'  $period  Selected window.
     * @param  array<int, array{date: string, opened: int, closed: int, moved: int}>  $points
     *                                                                                         One entry per day of the window, ascending, zero-filled.
     * @param  string|null  $journalSince  Day of the first journalled status
     *                                     change (null when the journal has none): closures and moves are
     *                                     only readable from that day on.
     */
    public function __construct(
        public readonly string $period,
        public readonly array $points,
        public readonly ?string $journalSince,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'period' => $this->period,
            'points' => $this->points,
            'journalSince' => $this->journalSince,
        ];
    }
}

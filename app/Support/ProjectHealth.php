<?php

namespace App\Support;

/**
 * Pure data object holding computed project health indicators.
 *
 * Never persisted: always calculated at read time from issues,
 * statuses, due dates, estimates and time entries.
 */
class ProjectHealth
{
    /**
     * @param  array{backlog: int, in_progress: int, done: int, cancelled: int}  $issuesByCategory
     */
    public function __construct(
        public readonly array $issuesByCategory,
        public readonly int $totalIssues,
        public readonly ?float $completionPercentage,
        public readonly int $overdueIssues,
        public readonly float $estimatedHours,
        public readonly float $loggedHours,
        public readonly ?float $consumptionRatio,
        public readonly ?float $expectedPercentage,
        public readonly ?string $status,
        public readonly ?string $targetDate,
        public readonly ?float $weightedCompletionPercentage = null,
        public readonly ?float $leafCompletionPercentage = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'issuesByCategory' => $this->issuesByCategory,
            'totalIssues' => $this->totalIssues,
            'completionPercentage' => $this->completionPercentage,
            'overdueIssues' => $this->overdueIssues,
            'estimatedHours' => $this->estimatedHours,
            'loggedHours' => $this->loggedHours,
            'consumptionRatio' => $this->consumptionRatio,
            'expectedPercentage' => $this->expectedPercentage,
            'status' => $this->status,
            'targetDate' => $this->targetDate,
            'weightedCompletionPercentage' => $this->weightedCompletionPercentage,
            'leafCompletionPercentage' => $this->leafCompletionPercentage,
        ];
    }
}

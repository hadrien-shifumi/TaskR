<?php

namespace App\Support;

/**
 * Per-person workload, always computed at read time.
 */
class PersonWorkload
{
    public function __construct(
        public readonly int $userId,
        public readonly string $userName,
        public readonly int $openIssues,
        public readonly int $totalIssues,
        public readonly int $overdueIssues,
        public readonly float $estimatedHours,
        public readonly float $loggedHours,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'userId' => $this->userId,
            'userName' => $this->userName,
            'openIssues' => $this->openIssues,
            'totalIssues' => $this->totalIssues,
            'overdueIssues' => $this->overdueIssues,
            'estimatedHours' => $this->estimatedHours,
            'loggedHours' => $this->loggedHours,
        ];
    }
}

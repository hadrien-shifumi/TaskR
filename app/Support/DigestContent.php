<?php

namespace App\Support;

/**
 * Aggregated digest payload for one account in one workspace.
 *
 * Pure data: the aggregator fills the five sections, the presenter turns
 * them into the camelCase Inertia shape. Never persisted.
 */
class DigestContent
{
    /**
     * @param  array<int, array<string, mixed>>  $overdueIssues
     * @param  array<int, array<string, mixed>>  $upcomingIssues
     * @param  array<int, array<string, mixed>>  $newAssignments
     * @param  array<int, array<string, mixed>>  $newComments
     * @param  array<string, mixed>  $workload
     * @param  array<int, array<string, mixed>>  $managerHighlights
     */
    public function __construct(
        public readonly array $overdueIssues,
        public readonly array $upcomingIssues,
        public readonly array $newAssignments,
        public readonly array $newComments,
        public readonly array $workload,
        public readonly array $managerHighlights,
    ) {}

    /**
     * True when the five sections carry nothing worth notifying.
     * The workload block is informational: it never makes a digest.
     */
    public function isEmpty(): bool
    {
        return $this->overdueIssues === []
            && $this->upcomingIssues === []
            && $this->newAssignments === []
            && $this->newComments === []
            && $this->managerHighlights === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'overdueIssues' => $this->overdueIssues,
            'upcomingIssues' => $this->upcomingIssues,
            'newAssignments' => $this->newAssignments,
            'newComments' => $this->newComments,
            'workload' => $this->workload,
            'managerHighlights' => $this->managerHighlights,
        ];
    }
}

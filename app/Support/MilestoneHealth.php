<?php

namespace App\Support;

/**
 * Pure data object holding the per-milestone projection of a project.
 *
 * Never persisted: always calculated at read time from the milestones
 * and their unarchived issue counts.
 */
class MilestoneHealth
{
    /**
     * @param  array<int, array<string, mixed>>  $milestones
     *                                                        One row per milestone, sorted by target date
     *                                                        (undated last) then title.
     */
    public function __construct(
        public readonly array $milestones,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'milestones' => $this->milestones,
        ];
    }
}

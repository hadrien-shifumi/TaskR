<?php

namespace Database\Factories;

use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditEvent>
 */
class AuditEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'issue_id' => null,
            'project_id' => Project::factory(),
            'knowledge_page_id' => null,
            'actor_id' => User::factory(),
            'type' => AuditEventType::ProjectCreated,
            'changes' => null,
        ];
    }

    /**
     * Journal an event on an issue instead of a project.
     */
    public function forIssue(?Issue $issue = null): static
    {
        return $this->state(fn () => [
            'issue_id' => $issue?->getKey() ?? Issue::factory(),
            'project_id' => null,
            'type' => AuditEventType::IssueCreated,
        ]);
    }

    /**
     * System event: no human actor.
     */
    public function withoutActor(): static
    {
        return $this->state(fn () => ['actor_id' => null]);
    }

    /**
     * @param  array<string, array{before: mixed, after: mixed}>  $changes
     */
    public function withChanges(array $changes): static
    {
        return $this->state(fn () => ['changes' => $changes]);
    }
}

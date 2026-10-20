<?php

namespace App\Presenters\Projects;

use App\Models\Project;
use App\Support\ProjectHealth;

class ProjectPresenter
{
    /**
     * Header of the project board and health pages.
     *
     * @return array{id: string, name: string, description: string|null, targetDate: string|null, createdAt: string|null}
     */
    public static function summary(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'description' => $project->description,
            'targetDate' => $project->target_date?->toDateString(),
            'createdAt' => $project->created_at?->toISOString(),
        ];
    }

    /**
     * Row of the MCP project list.
     *
     * @return array{id: string, name: string, description: string|null, targetDate: string|null, archivedAt: string|null}
     */
    public static function listItem(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'description' => $project->description,
            'targetDate' => $project->target_date?->toISOString(),
            'archivedAt' => $project->archived_at?->toISOString(),
        ];
    }

    /**
     * Row of the projects page, health badge included.
     *
     * @return array<string, mixed>
     */
    public static function pageCard(Project $project, ?ProjectHealth $health): array
    {
        return [
            ...self::summary($project),
            'archivedAt' => $project->archived_at?->toISOString(),
            'health' => $health?->toArray(),
        ];
    }

    /**
     * Card of the dashboard's active projects.
     *
     * @return array{id: string, name: string, health: array<string, mixed>}
     */
    public static function homeCard(Project $project, ProjectHealth $health): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'health' => $health->toArray(),
        ];
    }

    /**
     * Option of the project filter dropdowns.
     *
     * @return array{id: string, name: string}
     */
    public static function option(Project $project): array
    {
        return ['id' => $project->id, 'name' => $project->name];
    }
}

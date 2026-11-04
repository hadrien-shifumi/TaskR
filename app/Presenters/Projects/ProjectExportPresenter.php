<?php

namespace App\Presenters\Projects;

use App\Models\Project;

/**
 * Flat row of a project for the workspace backup — human-readable
 * values (names, dates), the same logical fields in the CSV. The
 * querying action loads nothing extra; the presenter never queries
 * on its own.
 */
class ProjectExportPresenter
{
    /**
     * Column headers of the CSV, in row order.
     *
     * @return list<string>
     */
    public static function headers(): array
    {
        return ['Identifiant', 'Nom', 'Mention', 'Description', 'Date cible', 'Archivé le'];
    }

    /**
     * Logical row of a project: camelCase keys, ISO-ish values.
     *
     * @return array<string, mixed>
     */
    public static function row(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'handle' => $project->handle,
            'description' => $project->description,
            'targetDate' => $project->target_date?->toDateString(),
            'archivedAt' => $project->archived_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * CSV cell values of a logical row, in header order: empty cells
     * for absent values.
     *
     * @param  array<string, mixed>  $row  A row() result
     * @return list<string>
     */
    public static function csvValues(array $row): array
    {
        return [
            (string) $row['id'],
            (string) $row['name'],
            (string) $row['handle'],
            (string) $row['description'],
            (string) $row['targetDate'],
            $row['archivedAt'] !== null ? (string) $row['archivedAt'] : '',
        ];
    }
}

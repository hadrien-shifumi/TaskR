<?php

namespace App\Presenters\Issues;

use App\Models\Issue;
use Illuminate\Support\Carbon;

/**
 * Flat row of an issue for the CSV/JSON exports — the same logical
 * fields in both formats, human-readable values (names, not ids).
 * The querying action loads creator, parent and the aggregated logged
 * time (logged_hours_sum); the presenter never queries on its own.
 */
class IssueExportPresenter
{
    /**
     * Column headers of the CSV export, in row order.
     *
     * @return list<string>
     */
    public static function headers(): array
    {
        return [
            'Identifiant', 'Titre', 'Projet', 'Statut', 'Catégorie', 'Priorité',
            'Labels', 'Assignés', 'Échéance', 'Estimation (heures)',
            'Temps consommé (heures)', 'Parent', 'Créateur', 'Créé le', 'Mis à jour le',
        ];
    }

    /**
     * Logical row of an issue: camelCase keys, structured multi-values
     * (labels and assignees as name lists), ISO timestamps for JSON.
     *
     * @return array<string, mixed>
     */
    public static function row(Issue $issue): array
    {
        return [
            'id' => $issue->id,
            'title' => $issue->title,
            'project' => $issue->project?->name,
            'status' => $issue->status?->name,
            'category' => $issue->status?->category?->value,
            'priority' => $issue->priority?->value,
            'labels' => $issue->labels->map(fn ($label) => $label->name)->values()->all(),
            'assignees' => $issue->assignees->map(fn ($user) => $user->name)->values()->all(),
            'dueDate' => $issue->due_date?->toDateString(),
            'estimatedHours' => $issue->estimated_hours !== null ? (float) $issue->estimated_hours : null,
            'loggedHours' => (float) ($issue->logged_hours_sum ?? 0),
            'parent' => $issue->parent?->title,
            'creator' => $issue->creator?->name,
            'createdAt' => $issue->created_at?->toISOString(),
            'updatedAt' => $issue->updated_at?->toISOString(),
        ];
    }

    /**
     * CSV cell values of a logical row, in header order: multi-values
     * joined inside one cell, spreadsheet-friendly dates, empty cells
     * for absent values.
     *
     * @param  array<string, mixed>  $row  A row() result
     * @return list<string>
     */
    public static function csvValues(array $row): array
    {
        return [
            (string) $row['id'],
            (string) $row['title'],
            (string) $row['project'],
            (string) $row['status'],
            (string) $row['category'],
            (string) $row['priority'],
            implode(', ', $row['labels']),
            implode(', ', $row['assignees']),
            (string) $row['dueDate'],
            $row['estimatedHours'] !== null ? number_format((float) $row['estimatedHours'], 2, '.', '') : '',
            number_format((float) $row['loggedHours'], 2, '.', ''),
            (string) $row['parent'],
            (string) $row['creator'],
            $row['createdAt'] !== null ? Carbon::parse($row['createdAt'])->format('Y-m-d H:i:s') : '',
            $row['updatedAt'] !== null ? Carbon::parse($row['updatedAt'])->format('Y-m-d H:i:s') : '',
        ];
    }
}

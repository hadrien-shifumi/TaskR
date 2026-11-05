<?php

namespace App\Presenters\TimeTracking;

use App\Models\TimeEntry;

/**
 * Flat row of a time entry for the CSV/JSON exports — the same logical
 * fields in both formats, human-readable values (names, not ids). The
 * querying action loads the author, the issue, its project and the
 * aggregated logged time of the ticket (logged_hours_sum); the
 * presenter never queries on its own.
 */
class TimeEntryExportPresenter
{
    /**
     * Column headers of the CSV export, in row order.
     *
     * @return list<string>
     */
    public static function headers(): array
    {
        return [
            'Identifiant', 'Ticket', 'Titre du ticket', 'Projet', 'Auteur',
            'Date du travail', 'Heures', 'Note',
            'Total consommé par ticket (heures)', 'Estimation (heures)',
        ];
    }

    /**
     * Logical row of a time entry: camelCase keys, ISO-ish values for
     * JSON. The per-ticket totals are repeated on every row of the
     * ticket so a pivot table reconciles consumption with the
     * estimate without any pre-aggregation.
     *
     * @return array<string, mixed>
     */
    public static function row(TimeEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'ticketId' => $entry->issue?->id,
            'ticketTitle' => $entry->issue?->title,
            'project' => $entry->issue?->project?->name,
            'author' => $entry->author?->name,
            'workedAt' => $entry->worked_at->toDateString(),
            'hours' => (float) $entry->hours,
            'note' => $entry->note,
            'ticketLoggedHours' => (float) ($entry->issue?->logged_hours_sum ?? 0),
            'ticketEstimatedHours' => $entry->issue?->estimated_hours !== null ? (float) $entry->issue->estimated_hours : null,
        ];
    }

    /**
     * CSV cell values of a logical row, in header order:
     * spreadsheet-friendly numbers, empty cells for absent values.
     *
     * @param  array<string, mixed>  $row  A row() result
     * @return list<string>
     */
    public static function csvValues(array $row): array
    {
        return [
            (string) $row['id'],
            (string) $row['ticketId'],
            (string) $row['ticketTitle'],
            (string) $row['project'],
            (string) $row['author'],
            (string) $row['workedAt'],
            number_format((float) $row['hours'], 2, '.', ''),
            (string) $row['note'],
            number_format((float) $row['ticketLoggedHours'], 2, '.', ''),
            $row['ticketEstimatedHours'] !== null ? number_format((float) $row['ticketEstimatedHours'], 2, '.', '') : '',
        ];
    }
}

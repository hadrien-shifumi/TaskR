<?php

namespace App\Presenters\Comments;

use App\Models\Comment;

/**
 * Flat row of a comment for the workspace backup — human-readable
 * values (names, not ids). The querying action loads the author and
 * the parent issue; the presenter never queries on its own.
 */
class CommentExportPresenter
{
    /**
     * Column headers of the CSV, in row order.
     *
     * @return list<string>
     */
    public static function headers(): array
    {
        return ['Identifiant', 'Ticket', 'Titre du ticket', 'Auteur', 'Créé le', 'Commentaire'];
    }

    /**
     * Logical row of a comment: camelCase keys, ISO-ish values.
     *
     * @return array<string, mixed>
     */
    public static function row(Comment $comment): array
    {
        return [
            'id' => $comment->id,
            'ticketId' => $comment->issue?->id,
            'ticketTitle' => $comment->issue?->title,
            'author' => $comment->author?->name,
            'createdAt' => $comment->created_at?->format('Y-m-d H:i:s'),
            'body' => $comment->body,
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
            (string) $row['ticketId'],
            (string) $row['ticketTitle'],
            (string) $row['author'],
            $row['createdAt'] !== null ? (string) $row['createdAt'] : '',
            (string) $row['body'],
        ];
    }
}

<?php

namespace App\Presenters\Comments;

use App\Models\Comment;

class CommentPresenter
{
    /**
     * Thread row shared by the MCP layer — a missing author stays null.
     *
     * @return array{id: string, authorId: int, authorName: string|null, body: string, createdAt: string}
     */
    public static function present(Comment $comment): array
    {
        return [
            'id' => $comment->id,
            'authorId' => $comment->author_id,
            'authorName' => $comment->author?->name,
            'body' => $comment->body,
            'createdAt' => $comment->created_at->toISOString(),
        ];
    }

    /**
     * Thread row of the issue fiche — a missing author shows as Unknown.
     *
     * @return array{id: string, authorId: int, authorName: string, body: string, createdAt: string}
     */
    public static function detail(Comment $comment): array
    {
        return [
            'id' => $comment->id,
            'authorId' => $comment->author_id,
            'authorName' => $comment->author?->name ?? 'Unknown',
            'body' => $comment->body,
            'createdAt' => $comment->created_at?->toISOString(),
        ];
    }
}

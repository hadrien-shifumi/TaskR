<?php

namespace App\Presenters\Chat;

use App\Models\ChatThread;

/**
 * The wire shape of a thread: the list card (the sidebar) and the full
 * read the thread page reloads. Author-only by construction — the list
 * and the detail read the requesting user's rows.
 */
class ChatThreadPresenter
{
    /**
     * The list card of the threads sidebar; the thread page reads the
     * same shape (detail === listItem — one shape, one source).
     *
     * @return array<string, mixed>
     */
    public static function listItem(ChatThread $thread): array
    {
        return [
            'id' => $thread->id,
            'title' => $thread->title,
            'createdAt' => $thread->created_at?->toISOString(),
            'updatedAt' => $thread->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(ChatThread $thread): array
    {
        return self::listItem($thread);
    }
}

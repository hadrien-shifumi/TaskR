<?php

namespace App\Actions\Comments;

use App\Models\Issue;
use Lorisleiva\Actions\Concerns\AsObject;

class ListComments
{
    use AsObject;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function handle(Issue $issue): array
    {
        return $issue->comments()
            ->with('author')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($comment) => [
                'id' => $comment->id,
                'authorId' => $comment->author_id,
                'authorName' => $comment->author?->name,
                'body' => $comment->body,
                'createdAt' => $comment->created_at->toISOString(),
            ])
            ->values()
            ->all();
    }
}

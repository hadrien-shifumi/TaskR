<?php

namespace App\Presenters\Chat;

use App\Models\ChatMessage;

/**
 * The wire shape of one message (thread page, events consumers, MCP
 * reuse later): camelCase, ULID ids, the generation state of assistant
 * messages and the compact tool journal.
 */
class ChatMessagePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(ChatMessage $message): array
    {
        return [
            'id' => $message->id,
            'threadId' => $message->thread_id,
            'role' => $message->role->value,
            'body' => $message->body,
            'status' => $message->status?->value,
            'error' => $message->error,
            'toolCalls' => $message->tool_calls,
            'usage' => $message->usage,
            'createdAt' => $message->created_at?->toISOString(),
        ];
    }
}

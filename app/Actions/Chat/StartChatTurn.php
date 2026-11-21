<?php

namespace App\Actions\Chat;

use App\Jobs\ChatCompletion;
use App\Models\ChatMessage;
use App\Models\ChatThread;

/**
 * The shared core of both chat POST shapes (a new conversation and the
 * next message of an existing one): the user's message and the pending
 * assistant answer persist in one write, the generation job follows,
 * and the 202 contract stays one — threadId, userMessageId,
 * assistantMessageId, status.
 */
class StartChatTurn
{
    public const int CONTENT_MAX_LENGTH = 4000;

    public const int TITLE_MAX_CHARS = 60;

    /**
     * @return array<string, mixed>
     */
    public static function contentRules(): array
    {
        return [
            'content' => ['required', 'string', 'min:1', 'max:'.self::CONTENT_MAX_LENGTH],
        ];
    }

    /**
     * The thread's initial title, derived from its first message.
     */
    public static function titleFrom(string $content): string
    {
        /** @var string $trimmed */
        $trimmed = preg_replace('/\s+/', ' ', trim($content)) ?? $content;

        if (mb_strlen($trimmed) <= self::TITLE_MAX_CHARS) {
            return $trimmed;
        }

        return rtrim(mb_substr($trimmed, 0, self::TITLE_MAX_CHARS)).'…';
    }

    /**
     * Start the turn on one thread:
     *
     * @return array{threadId: string, userMessageId: string, assistantMessageId: string, status: string}
     */
    public function start(ChatThread $thread, string $content): array
    {
        $userMessage = ChatMessage::create([
            'thread_id' => $thread->id,
            'role' => 'user',
            'body' => $content,
        ]);

        $assistantMessage = ChatMessage::create([
            'thread_id' => $thread->id,
            'role' => 'assistant',
            'status' => 'pending',
        ]);

        ChatCompletion::dispatch($thread->id, $assistantMessage->id);

        return [
            'threadId' => $thread->id,
            'userMessageId' => $userMessage->id,
            'assistantMessageId' => $assistantMessage->id,
            'status' => $assistantMessage->status->value,
        ];
    }
}

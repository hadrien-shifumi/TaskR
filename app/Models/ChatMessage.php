<?php

namespace App\Models;

use App\Enums\ChatMessageRole;
use App\Enums\ChatMessageStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One turn of a chat conversation, user or assistant, and its settled
 * state.
 *
 * A user message is complete the moment it is saved. An assistant message
 * is created pending while the queued completion runs, then settles
 * complete (final body + tool journal + token usage) or failed
 * (displayable error line, never a silent dead-end). The streaming
 * fragments never land here — they live in Mercure only.
 */
class ChatMessage extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'thread_id',
        'role',
        'body',
        'tool_calls',
        'usage',
        'status',
        'error',
    ];

    protected $casts = [
        'role' => ChatMessageRole::class,
        'status' => ChatMessageStatus::class,
        'tool_calls' => 'array',
        'usage' => 'array',
    ];

    /**
     * @return BelongsTo<ChatThread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(ChatThread::class, 'thread_id');
    }
}

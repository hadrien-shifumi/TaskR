<?php

namespace App\Jobs;

use App\Chat\ChatCompletionLoop;
use App\Chat\ChatPublisher;
use App\Chat\Exceptions\ChatFailure;
use App\Enums\ChatMessageStatus;
use App\Models\ChatThread;
use App\Support\WorkspaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * The queued half of a chat turn: the tool-calling loop runs here, the
 * fragments publish on the thread's private Mercure channel as they
 * arrive, and the assistant message settles — complete with body, tool
 * journal and token usage, or failed with a displayable line.
 *
 * The turn never retries: the reader already saw fragments on the
 * channel, a replayed generation would append a second answer to the
 * same question. One attempt, an explicit failure state on the thread.
 */
class ChatCompletion implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  string  $threadId  the ULID of the thread the turn runs in
     * @param  string  $assistantMessageId  the ULID of the pending assistant message
     */
    public function __construct(
        public string $threadId,
        public string $assistantMessageId,
    ) {}

    public function handle(ChatCompletionLoop $loop): void
    {
        // The thread resolves outside any tenant context (the worker
        // has none), then lends the job its own workspace for the whole
        // turn — restored to null in the finally, whatever the outcome.
        $thread = ChatThread::withoutGlobalScopes()->findOrFail($this->threadId);

        app(WorkspaceContext::class)->set($thread->workspace);

        try {
            $assistantMessage = $thread->messages()->findOrFail($this->assistantMessageId);

            $loop->run($thread, $assistantMessage);
            // The loop settles the turn itself: the streamed fragments
            // already reached the channel, nothing to retry here.
        } catch (ChatFailure $failure) {
            $this->failTurn($thread, $this->assistantMessageId, $failure->messageKey(), $failure->getMessage());
        } finally {
            app(WorkspaceContext::class)->set(null);
        }
    }

    /**
     * The displayable failure is part of the conversation: the assistant
     * message settles failed with the same line the client reads on the
     * thread's channel. The technical detail keeps to the logs.
     */
    private function failTurn(ChatThread $thread, string $assistantMessageId, string $messageKey, ?string $detail): void
    {
        $error = strval(__($messageKey));

        $assistantMessage = $thread->messages()->findOrFail($assistantMessageId);

        $assistantMessage->forceFill([
            'status' => ChatMessageStatus::Failed,
            'error' => $error,
        ])->save();

        app(ChatPublisher::class)->error(
            $thread->id,
            $assistantMessage->id,
            $error,
        );

        if ($detail !== null && $detail !== '') {
            Log::warning('Chat completion failed.', ['message_id' => $assistantMessage->id, 'detail' => $detail]);
        }
    }
}

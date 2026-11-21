<?php

namespace App\Chat;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastingFactory;

/**
 * The production ChatPublisher: every event publishes synchronously
 * through the Mercure broadcaster — from the queue worker this is an
 * HTTP publish to the hub, so the deployment points MERCURE_URL at the
 * hub the web container threads. The dynamic envelope (channels, event
 * name, payload) is the framework's own; the client subscribes and
 * reads `event` + `payload` from it.
 */
final class BroadcastChatPublisher implements ChatPublisher
{
    public function chunk(string $threadId, string $messageId, string $delta): void
    {
        $this->publish($threadId, 'assistant.chunk', [
            'messageId' => $messageId,
            'delta' => $delta,
        ]);
    }

    public function toolStarted(string $threadId, string $messageId, string $callId, string $name): void
    {
        $this->publish($threadId, 'tool.started', [
            'messageId' => $messageId,
            'callId' => $callId,
            'name' => $name,
        ]);
    }

    public function toolResult(string $threadId, string $messageId, string $callId, string $name, bool $ok, string $summary): void
    {
        $this->publish($threadId, 'tool.result', [
            'messageId' => $messageId,
            'callId' => $callId,
            'name' => $name,
            'ok' => $ok,
            'summary' => $summary,
        ]);
    }

    public function done(string $threadId, string $messageId, string $body, ?array $usage): void
    {
        $this->publish($threadId, 'assistant.done', [
            'messageId' => $messageId,
            'body' => $body,
            'usage' => $usage,
        ]);
    }

    public function error(string $threadId, string $messageId, string $message): void
    {
        $this->publish($threadId, 'assistant.error', [
            'messageId' => $messageId,
            'error' => $message,
        ]);
    }

    /**
     * One synchronous publish onto the thread's private channel — from
     * the queue worker this is an HTTP publish to the hub the web
     * container threads (MERCURE_URL); the connection stays the default
     * one (BROADCAST_CONNECTION).
     *
     * @param  array<string, mixed>  $payload
     */
    private function publish(string $threadId, string $event, array $payload): void
    {
        app(BroadcastingFactory::class)
            ->connection()
            ->broadcast(
                [new PrivateChannel('chat-threads.'.$threadId)],
                $event,
                $payload,
            );
    }
}

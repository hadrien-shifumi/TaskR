<?php

namespace App\Chat;

/**
 * Publishes one conversation's streaming events onto its private
 * Mercure channel (chat-threads.{ulid}) — the seam the queued turn and
 * the loop depend on, and the seam the tests fake: the real
 * implementation goes through the Mercure broadcaster, the tests record
 * and assert without ever opening a hub connection.
 */
interface ChatPublisher
{
    /**
     * A fragment of the assistant's text being streamed.
     */
    public function chunk(string $threadId, string $messageId, string $delta): void;

    /**
     * The assistant started one tool call.
     */
    public function toolStarted(string $threadId, string $messageId, string $callId, string $name): void;

    /**
     * A tool call answered. `summary` is a short displayable label (the
     * tool's readable name in the application language) — full tool
     * payloads are never published.
     */
    public function toolResult(string $threadId, string $messageId, string $callId, string $name, bool $ok, string $summary): void;

    /**
     * The turn settled: the final text and the provider's usage block.
     */
    public function done(string $threadId, string $messageId, string $body, ?array $usage): void;

    /**
     * The turn failed: a displayable, translated error line.
     */
    public function error(string $threadId, string $messageId, string $message): void;
}

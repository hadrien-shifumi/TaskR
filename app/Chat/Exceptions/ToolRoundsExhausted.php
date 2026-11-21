<?php

namespace App\Chat\Exceptions;

/**
 * The model kept calling tools past the exploration budget: the turn
 * stops rather than loop indefinitely. The user reads the honest state
 * — the assistant ran out of exploration steps — not a stack trace.
 */
final class ToolRoundsExhausted extends ChatFailure
{
    public const string MESSAGE_KEY = 'chat.errors.exploration_exceeded';

    public function messageKey(): string
    {
        return self::MESSAGE_KEY;
    }
}

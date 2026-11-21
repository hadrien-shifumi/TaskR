<?php

namespace App\Chat\Exceptions;

/**
 * The thread's workspace has no usable chat provider: the turn cannot
 * start, and the job reports the configuration gap on the assistant
 * message instead of a 500.
 */
final class ChatNotConfigured extends ChatFailure
{
    public const string MESSAGE_KEY = 'chat.errors.not_configured';

    public function messageKey(): string
    {
        return self::MESSAGE_KEY;
    }
}

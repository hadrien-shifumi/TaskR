<?php

namespace App\Chat\Exceptions;

/**
 * The chat provider refused or broke the request: a non-2xx status, an
 * unreadable body or a dead connection. The user reads the generic
 * provider line; the HTTP status and body feed the logs only.
 */
final class ProviderFailure extends ChatFailure
{
    public const string MESSAGE_KEY = 'chat.errors.provider_failed';

    public function messageKey(): string
    {
        return self::MESSAGE_KEY;
    }
}

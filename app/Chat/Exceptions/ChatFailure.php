<?php

namespace App\Chat\Exceptions;

use RuntimeException;

/**
 * The base of every chat-generation failure a queued turn reports to
 * the thread: the technical detail travels in the message (the logs),
 * the displayable text resolves from a translation key at catch time,
 * in the turn's language. The completion job maps each subclass to the
 * turn's failed state — never a 500, never a dangling pending answer.
 */
abstract class ChatFailure extends RuntimeException
{
    /**
     * The translation key of the displayable error the user reads.
     */
    abstract public function messageKey(): string;
}

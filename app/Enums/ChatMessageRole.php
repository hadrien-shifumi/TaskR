<?php

namespace App\Enums;

/**
 * Who wrote a chat message: the human user or the assistant.
 */
enum ChatMessageRole: string
{
    case User = 'user';

    case Assistant = 'assistant';
}

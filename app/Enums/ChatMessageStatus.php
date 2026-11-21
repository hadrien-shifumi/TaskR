<?php

namespace App\Enums;

/**
 * The outcome of an assistant turn: its message is created pending while
 * the queued completion runs, then settles complete (body + usage saved)
 * or failed (displayable error line saved). User messages carry no
 * status — only generated turns have one.
 */
enum ChatMessageStatus: string
{
    case Pending = 'pending';

    case Complete = 'complete';

    case Failed = 'failed';
}

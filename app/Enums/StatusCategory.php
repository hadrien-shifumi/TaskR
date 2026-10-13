<?php

namespace App\Enums;

enum StatusCategory: string
{
    case Backlog = 'backlog';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Cancelled = 'cancelled';
}

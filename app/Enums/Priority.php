<?php

namespace App\Enums;

enum Priority: string
{
    case None = 'none';
    case Low = 'low';
    case High = 'high';
    case Urgent = 'urgent';
}

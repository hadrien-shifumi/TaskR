<?php

namespace App\Enums;

enum ProjectRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Member = 'member';
}

<?php

namespace App\Enums;

enum ProjectRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Member = 'member';
    case Guest = 'guest';

    /**
     * Rank in the N+1 hierarchy (D5): Owner > Manager > Member > Guest.
     * The rank alone decides who pilots whom on a project roster.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Owner => 3,
            self::Manager => 2,
            self::Member => 1,
            self::Guest => 0,
        };
    }

    /**
     * Strict N+1 superiority (D9): true only above the other role —
     * never on a pair, never on a superior.
     */
    public function outranks(self $other): bool
    {
        return $this->rank() > $other->rank();
    }
}

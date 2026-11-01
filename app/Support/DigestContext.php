<?php

namespace App\Support;

use App\Enums\DigestFrequency;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * Everything the digest aggregator needs to run for one account in one
 * workspace. The workspace is always handed in by the caller (the job
 * resolves the active one) — the aggregator never resolves it itself.
 *
 * `now` is an immutable instant already expressed in the account timezone,
 * so every date bound (overdue, upcoming, windows) is computed in the
 * user's calendar, not the server's.
 */
class DigestContext
{
    public function __construct(
        public readonly User $user,
        public readonly Workspace $workspace,
        public readonly NotificationPreference $preference,
        public readonly CarbonImmutable $now,
        public readonly ?CarbonImmutable $since = null,
    ) {}

    /**
     * How many days ahead an upcoming due date still belongs to the digest.
     */
    public function thresholdDays(): int
    {
        return $this->preference->upcoming_threshold_days;
    }

    /**
     * Start of the "since the last digest" window: the previous digest
     * date when known, otherwise one frequency period before now.
     */
    public function windowStart(): CarbonImmutable
    {
        if ($this->since !== null) {
            return $this->since;
        }

        return match ($this->preference->frequency) {
            DigestFrequency::Weekly => $this->now->subWeek(),
            default => $this->now->subDay(),
        };
    }
}

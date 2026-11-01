<?php

namespace App\Actions\Notifications;

use App\Enums\DigestFrequency;
use App\Models\NotificationPreference;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Select the accounts whose digest is due at a given instant. Run hourly:
 * an account is due when its local clock hits 08:00, on the day matching
 * its frequency (daily: every day, weekly: Monday only), and when no
 * digest was already sent that local day.
 *
 * The timezone math happens in PHP, not in SQL: SQLite and PostgreSQL
 * disagree on how to shift a timestamp into an IANA timezone, and the
 * local day may differ from the UTC one.
 */
class SendDueDigests
{
    use AsObject;

    /** Local hour at which an account receives its digest. */
    public const SEND_HOUR = 8;

    /**
     * @return Collection<int, string> User ids of the due accounts
     */
    public function handle(CarbonImmutable $now): Collection
    {
        return NotificationPreference::query()
            ->where('enabled', true)
            ->orderBy('id')
            ->get()
            ->filter(fn (NotificationPreference $preference) => $this->isDue($preference, $now))
            ->pluck('user_id')
            ->values();
    }

    private function isDue(NotificationPreference $preference, CarbonImmutable $now): bool
    {
        $localNow = $this->localNow($preference, $now);

        if ($localNow === null || $localNow->hour !== self::SEND_HOUR) {
            return false;
        }

        if ($preference->frequency === DigestFrequency::Weekly && ! $localNow->isMonday()) {
            return false;
        }

        return ! $this->alreadySentToday($preference, $localNow);
    }

    /**
     * The instant in the account timezone, or null when the stored
     * timezone is unknown — such an account is skipped without failing
     * the whole run.
     */
    private function localNow(NotificationPreference $preference, CarbonImmutable $now): ?CarbonImmutable
    {
        try {
            return $now->setTimezone($preference->timezone);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Anti-duplicate guard: the command runs every hour, an account served
     * today in its own calendar must not be served again.
     */
    private function alreadySentToday(NotificationPreference $preference, CarbonImmutable $localNow): bool
    {
        if ($preference->last_digest_at === null) {
            return false;
        }

        return $preference->last_digest_at
            ->setTimezone($localNow->getTimezone())
            ->isSameDay($localNow);
    }
}

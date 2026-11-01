<?php

namespace App\Presenters\Auth;

use App\Enums\DigestFrequency;
use App\Models\NotificationPreference;

class NotificationPreferencesPresenter
{
    /**
     * Settings page shape. Falls back to the opt-out defaults when the user
     * never saved their preferences (no row yet).
     *
     * @return array{enabled: bool, frequency: string, upcomingThresholdDays: int, timezone: string, lastDigestAt: string|null}
     */
    public static function present(?NotificationPreference $preference): array
    {
        if ($preference === null) {
            return [
                'enabled' => false,
                'frequency' => DigestFrequency::Daily->value,
                'upcomingThresholdDays' => 7,
                'timezone' => 'Europe/Paris',
                'lastDigestAt' => null,
            ];
        }

        return [
            'enabled' => $preference->enabled,
            'frequency' => $preference->frequency->value,
            'upcomingThresholdDays' => $preference->upcoming_threshold_days,
            'timezone' => $preference->timezone,
            'lastDigestAt' => $preference->last_digest_at?->toISOString(),
        ];
    }
}

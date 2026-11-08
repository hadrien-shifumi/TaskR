<?php

namespace App\Actions\TimeTracking\Concerns;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Validation\ValidationException;

trait InteractsWithTimesheetWeek
{
    /**
     * Parse a `Y-m-d` week anchor into its Monday (start of week), or
     * null when the value is absent or not a strict calendar date. Also
     * used to validate a day cell's key format — the range check against
     * the submitted week happens at the call site.
     */
    private function parseWeekAnchor(mixed $week): ?CarbonImmutable
    {
        if (! is_string($week) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $week) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $week);
        } catch (InvalidFormatException|InvalidArgumentException) {
            return null;
        }

        // Rejects overflowing dates such as 2026-13-45, which Carbon rolls over.
        if ($date === false || $date->format('Y-m-d') !== $week) {
            return null;
        }

        return $date->startOfWeek();
    }

    /**
     * Parse a day cell's key into the exact day — no normalization: a
     * mid-week day must keep its own date, or every cell would collapse
     * onto the week's Monday. Null when the key is not a strict date.
     */
    private function parseDayCell(mixed $day): ?CarbonImmutable
    {
        if (! is_string($day) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $day);
        } catch (InvalidFormatException|InvalidArgumentException) {
            return null;
        }

        // Rejects overflowing dates such as 2026-13-45, which Carbon rolls over.
        if ($date === false || $date->format('Y-m-d') !== $day) {
            return null;
        }

        return $date;
    }

    /**
     * Whether the week sits outside the retroactivity window: the current
     * week at the most recent, and at the oldest the Monday of the week
     * eight weeks back — the same Monday-based definition as the dashboard's
     * "logged this week" counter, so the grid and the counter can never
     * drift apart.
     */
    private function weekOutOfBounds(CarbonImmutable $weekStart): bool
    {
        $latest = CarbonImmutable::today()->startOfWeek();
        $oldest = CarbonImmutable::today()->subWeeks(8)->startOfWeek();

        return $weekStart->gt($latest) || $weekStart->lt($oldest);
    }

    private function abortIfWeekOutOfBounds(CarbonImmutable $weekStart): void
    {
        if ($this->weekOutOfBounds($weekStart)) {
            throw ValidationException::withMessages([
                'week' => __('validation.time_entry.week_out_of_window'),
            ]);
        }
    }
}

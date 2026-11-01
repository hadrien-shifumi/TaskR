<?php

namespace Tests\Unit;

use App\Enums\DigestFrequency;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Models\Workspace;
use App\Support\DigestContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DigestContextTest extends TestCase
{
    use RefreshDatabase;

    private function preference(DigestFrequency $frequency, int $thresholdDays = 7): NotificationPreference
    {
        return NotificationPreference::factory()->enabled()->make([
            'frequency' => $frequency,
            'upcoming_threshold_days' => $thresholdDays,
        ]);
    }

    private function context(DigestFrequency $frequency, ?CarbonImmutable $since = null, int $thresholdDays = 7): DigestContext
    {
        return new DigestContext(
            user: User::factory()->make(),
            workspace: Workspace::factory()->make(),
            preference: $this->preference($frequency, $thresholdDays),
            now: CarbonImmutable::parse('2026-11-01 09:00:00', 'Europe/Paris'),
            since: $since,
        );
    }

    public function test_threshold_days_comes_from_the_preference(): void
    {
        $this->assertSame(21, $this->context(DigestFrequency::Daily, thresholdDays: 21)->thresholdDays());
    }

    public function test_the_daily_window_start_is_one_day_before_now_without_a_previous_digest(): void
    {
        $this->assertSame(
            '2026-10-31 09:00:00',
            $this->context(DigestFrequency::Daily)->windowStart()->format('Y-m-d H:i:s'),
        );
    }

    public function test_the_weekly_window_start_is_seven_days_before_now_without_a_previous_digest(): void
    {
        $this->assertSame(
            '2026-10-25 09:00:00',
            $this->context(DigestFrequency::Weekly)->windowStart()->format('Y-m-d H:i:s'),
        );
    }

    public function test_the_window_start_prefers_the_previous_digest_date(): void
    {
        $since = CarbonImmutable::parse('2026-10-20 18:30:00', 'Europe/Paris');

        $this->assertSame(
            '2026-10-20 18:30:00',
            $this->context(DigestFrequency::Daily, $since)->windowStart()->format('Y-m-d H:i:s'),
        );
    }

    public function test_the_window_start_keeps_the_account_timezone(): void
    {
        $this->assertSame(
            'Europe/Paris',
            $this->context(DigestFrequency::Weekly)->windowStart()->getTimezone()->getName(),
        );
    }
}

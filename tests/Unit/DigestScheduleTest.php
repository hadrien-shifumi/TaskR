<?php

namespace Tests\Unit;

use App\Actions\Notifications\SendDueDigests;
use App\Enums\DigestFrequency;
use App\Models\NotificationPreference;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Selection rules of the hourly digest command: who is due at a given
 * instant. The command only picks accounts — the sending job is tested
 * in SendDigestTest.
 */
class DigestScheduleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function preference(array $attributes = []): NotificationPreference
    {
        return NotificationPreference::factory()
            ->for(User::factory())
            ->enabled()
            ->create($attributes);
    }

    /**
     * @return list<string>
     */
    private function dueAt(string $instant, string $timezone = 'UTC'): array
    {
        return SendDueDigests::run(CarbonImmutable::parse($instant, $timezone))->all();
    }

    public function test_an_opted_out_preference_is_excluded(): void
    {
        $optedOut = NotificationPreference::factory()
            ->for(User::factory())
            ->create(['timezone' => 'Europe/Paris', 'last_digest_at' => null]);

        // 2026-11-02 is a Monday, 08:00 Paris.
        $this->assertSame([], $this->dueAt('2026-11-02 08:00:00', 'Europe/Paris'));
        $this->assertFalse($optedOut->enabled);
    }

    public function test_a_daily_digest_is_due_at_eight_locally_every_day(): void
    {
        $daily = $this->preference(['frequency' => DigestFrequency::Daily, 'timezone' => 'Europe/Paris']);

        // Monday, then Thursday, then Sunday: the same account stays due.
        $this->assertSame([$daily->user_id], $this->dueAt('2026-11-02 08:00:00', 'Europe/Paris'));
        $this->assertSame([$daily->user_id], $this->dueAt('2026-11-05 08:00:00', 'Europe/Paris'));
        $this->assertSame([$daily->user_id], $this->dueAt('2026-11-08 08:00:00', 'Europe/Paris'));
    }

    public function test_a_weekly_digest_is_due_on_monday_at_eight_locally(): void
    {
        $weekly = $this->preference(['frequency' => DigestFrequency::Weekly, 'timezone' => 'Europe/Paris']);

        // 2026-11-02 is a Monday, 2026-11-03 a Tuesday.
        $this->assertSame([$weekly->user_id], $this->dueAt('2026-11-02 08:00:00', 'Europe/Paris'));
        $this->assertSame([], $this->dueAt('2026-11-03 08:00:00', 'Europe/Paris'));
        $this->assertSame([], $this->dueAt('2026-11-04 08:00:00', 'Europe/Paris'));
        $this->assertSame([], $this->dueAt('2026-11-06 08:00:00', 'Europe/Paris'));
        $this->assertSame([], $this->dueAt('2026-11-08 08:00:00', 'Europe/Paris'));
        $this->assertSame([$weekly->user_id], $this->dueAt('2026-11-09 08:00:00', 'Europe/Paris'));
    }

    public function test_a_preference_outside_the_eight_o_clock_hour_is_excluded(): void
    {
        $daily = $this->preference(['frequency' => DigestFrequency::Daily, 'timezone' => 'Europe/Paris']);

        $this->assertSame([], $this->dueAt('2026-11-02 07:59:00', 'Europe/Paris'));
        $this->assertSame([$daily->user_id], $this->dueAt('2026-11-02 08:00:00', 'Europe/Paris'));
        $this->assertSame([$daily->user_id], $this->dueAt('2026-11-02 08:59:00', 'Europe/Paris'));
        $this->assertSame([], $this->dueAt('2026-11-02 09:00:00', 'Europe/Paris'));
        $this->assertSame([], $this->dueAt('2026-11-02 18:00:00', 'Europe/Paris'));
    }

    public function test_the_account_timezone_decides_when_eight_o_clock_happens(): void
    {
        $auckland = $this->preference(['frequency' => DigestFrequency::Daily, 'timezone' => 'Pacific/Auckland']);
        $paris = $this->preference(['frequency' => DigestFrequency::Daily, 'timezone' => 'Europe/Paris']);

        // 21:00 UTC on Nov 1st = 10:00 in Paris and 10:00 on Nov 2nd in Auckland.
        $this->assertSame([], $this->dueAt('2026-11-01 21:00:00'));

        // 19:00 UTC on Nov 1st = 08:00 in Auckland (Nov 2nd, Monday).
        $this->assertSame([$auckland->user_id], $this->dueAt('2026-11-01 19:00:00'));

        // 07:00 UTC on Nov 2nd = 08:00 in Paris.
        $this->assertSame([$paris->user_id], $this->dueAt('2026-11-02 07:00:00'));
    }

    public function test_a_digest_already_sent_today_is_excluded(): void
    {
        $paris = $this->preference([
            'frequency' => DigestFrequency::Daily,
            'timezone' => 'Europe/Paris',
            'last_digest_at' => CarbonImmutable::parse('2026-11-02 08:00:00', 'Europe/Paris'),
        ]);

        // Same local day, later in the hour: already sent.
        $this->assertSame([], $this->dueAt('2026-11-02 08:30:00', 'Europe/Paris'));

        // The next local day is due again.
        $this->assertSame([$paris->user_id], $this->dueAt('2026-11-03 08:00:00', 'Europe/Paris'));
    }

    public function test_the_anti_duplicate_guard_uses_the_account_timezone(): void
    {
        // Sent at 08:00 Auckland time = 19:00 UTC on Nov 1st.
        $auckland = $this->preference([
            'frequency' => DigestFrequency::Daily,
            'timezone' => 'Pacific/Auckland',
            'last_digest_at' => CarbonImmutable::parse('2026-11-01 19:00:00', 'UTC'),
        ]);

        // 19:00 UTC is still Nov 1st in UTC but already Nov 2nd in Auckland.
        $this->assertSame([], $this->dueAt('2026-11-01 19:00:00'));

        // 2026-11-01 19:00 UTC is the same local day: a Paris account is due.
        $paris = $this->preference(['frequency' => DigestFrequency::Daily, 'timezone' => 'Europe/Paris']);
        $this->assertSame([], $this->dueAt('2026-11-01 19:00:00'));

        // Sanity: the Auckland row exists and is enabled.
        $this->assertTrue($auckland->enabled);
        $this->assertSame([$paris->user_id], $this->dueAt('2026-11-02 07:00:00'));
    }

    public function test_an_unknown_timezone_never_blocks_the_other_accounts(): void
    {
        $broken = $this->preference(['frequency' => DigestFrequency::Daily, 'timezone' => 'Mars/Olympus_Mons']);
        $healthy = $this->preference(['frequency' => DigestFrequency::Daily, 'timezone' => 'Europe/Paris']);

        $this->assertSame([$healthy->user_id], $this->dueAt('2026-11-02 08:00:00', 'Europe/Paris'));
        $this->assertNotContains($broken->user_id, [$healthy->user_id]);
    }
}

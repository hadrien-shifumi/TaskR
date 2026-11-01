<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Issues\SetAssignees;
use App\Actions\Notifications\BuildDigest;
use App\Actions\Notifications\SendDueDigests;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\DigestFrequency;
use App\Enums\ProjectRole;
use App\Jobs\SendAccountDigest;
use App\Mail\DigestMail;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\NotificationPreference;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Support\DigestContent;
use App\Support\DigestContext;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

/**
 * End-to-end digest sending: the hourly command dispatches one job per
 * due account, the job aggregates, mails and stamps last_digest_at.
 */
class SendDigestTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private Project $project;

    /** @var array<string, Status> */
    private array $statuses = [];

    protected function setUp(): void
    {
        parent::setUp();

        // 2026-11-02 is a Monday: 08:00 in Paris.
        $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00:00', 'Europe/Paris'));

        $this->owner = User::factory()->create();
        $this->workspace = CreateWorkspace::run($this->owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($this->workspace);

        $this->project = CreateProject::run($this->workspace, $this->owner, ['name' => 'Site vitrine']);

        foreach ($this->workspace->statuses as $status) {
            $this->statuses[$status->category->value] = $status;
        }
    }

    private function member(string $role = 'member'): User
    {
        $user = User::factory()->create();
        Membership::create([
            'user_id' => $user->id,
            'workspace_id' => $this->workspace->id,
            'role' => $role,
        ]);

        return $user;
    }

    private function preference(User $user, array $attributes = []): NotificationPreference
    {
        return NotificationPreference::factory()->for($user)->enabled()->create(array_merge([
            'frequency' => DigestFrequency::Daily,
            'timezone' => 'Europe/Paris',
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function issue(array $overrides = [], ?Project $project = null, string $category = 'backlog'): Issue
    {
        return CreateIssue::run($this->workspace, array_merge([
            'project_id' => ($project ?? $this->project)->id,
            'title' => 'Issue '.fake()->unique()->numberBetween(1, 100000),
            'status_id' => $this->statuses[$category]->id,
        ], $overrides));
    }

    private function overdueIssueFor(User $user, ?Project $project = null): Issue
    {
        $issue = $this->issue(['due_date' => now()->subDays(2)->toDateString()], $project, 'in_progress');
        SetAssignees::run($issue, [$user->id]);

        return $issue;
    }

    private function runJobFor(User $user): void
    {
        SendAccountDigest::dispatchSync($user);
    }

    // ── Le job ─────────────────────────────────────────────────────────

    public function test_a_non_empty_digest_is_mailed_and_stamps_the_last_digest_date(): void
    {
        Mail::fake();

        $member = $this->member();
        $preference = $this->preference($member);
        $this->overdueIssueFor($member);

        $this->runJobFor($member);

        Mail::assertSent(DigestMail::class, fn (DigestMail $mail) => $mail->hasTo($member->email));

        $this->assertSame(
            now()->toDateTimeString(),
            $preference->fresh()->last_digest_at->toDateTimeString(),
        );
    }

    public function test_an_empty_digest_sends_nothing_and_keeps_the_last_digest_date(): void
    {
        Mail::fake();

        $member = $this->member();
        $previous = CarbonImmutable::parse('2026-10-31 08:00:00', 'Europe/Paris');
        $preference = $this->preference($member, ['last_digest_at' => $previous]);

        $this->runJobFor($member);

        Mail::assertNothingSent();
        $this->assertSame(
            $previous->toDateTimeString(),
            $preference->fresh()->last_digest_at->toDateTimeString(),
        );
    }

    public function test_an_opted_out_account_receives_nothing(): void
    {
        Mail::fake();

        $member = $this->member();
        $preference = NotificationPreference::factory()->for($member)->create([
            'timezone' => 'Europe/Paris',
        ]);
        $this->overdueIssueFor($member);

        $this->runJobFor($member);

        Mail::assertNothingSent();
        $this->assertNull($preference->fresh()->last_digest_at);
    }

    public function test_an_account_without_a_workspace_receives_nothing(): void
    {
        Mail::fake();

        $lonely = User::factory()->create();
        $this->preference($lonely);

        $this->runJobFor($lonely);

        Mail::assertNothingSent();
    }

    public function test_the_job_is_queued(): void
    {
        $this->assertInstanceOf(ShouldQueue::class, new SendAccountDigest($this->owner));
    }

    // ── Périmètre ──────────────────────────────────────────────────────

    public function test_the_digest_is_limited_to_the_primary_workspace(): void
    {
        Mail::fake();

        $member = $this->member();
        $this->preference($member);

        // "Alpha" sorts before the workspace "WS": it becomes the primary one.
        // CreateWorkspace::run binds nothing itself, so the context is set
        // explicitly for the fixture writes of the second workspace.
        $alpha = CreateWorkspace::run($member, ['name' => 'Alpha']);
        app(WorkspaceContext::class)->set($alpha);
        $alphaProject = CreateProject::run($alpha, $member, ['name' => 'Alpha project']);
        $alphaStatus = $alpha->statuses()->where('category', 'in_progress')->firstOrFail();
        $alphaIssue = CreateIssue::run($alpha, [
            'project_id' => $alphaProject->id,
            'title' => 'Alpha late',
            'status_id' => $alphaStatus->id,
            'due_date' => now()->subDays(2)->toDateString(),
        ]);
        SetAssignees::run($alphaIssue, [$member->id]);

        app(WorkspaceContext::class)->set($this->workspace);
        $this->overdueIssueFor($member);

        $this->runJobFor($member);

        Mail::assertSent(DigestMail::class, function (DigestMail $mail) use ($alphaIssue): bool {
            $ids = array_map(fn (array $row) => $row['id'], $mail->digest['overdueIssues']);

            return $mail->digest['workspaceName'] === 'Alpha'
                && $ids === [$alphaIssue->id];
        });
    }

    public function test_a_guest_only_gets_its_roster_without_manager_kpis(): void
    {
        Mail::fake();

        $guest = $this->member('guest');
        $this->preference($guest);

        $inScope = CreateProject::run($this->workspace, $this->owner, ['name' => 'Alpha']);
        $outOfScope = CreateProject::run($this->workspace, $this->owner, ['name' => 'Beta']);
        ProjectMember::create([
            'project_id' => $inScope->id,
            'user_id' => $guest->id,
            'role' => ProjectRole::Manager->value,
        ]);

        $visible = $this->overdueIssueFor($guest, $inScope);
        $hidden = $this->overdueIssueFor($guest, $outOfScope);

        $this->runJobFor($guest);

        Mail::assertSent(DigestMail::class, function (DigestMail $mail) use ($visible, $hidden): bool {
            $ids = array_map(fn (array $row) => $row['id'], $mail->digest['overdueIssues']);

            return $ids === [$visible->id]
                && ! in_array($hidden->id, $ids, true)
                && $mail->digest['managerHighlights'] === [];
        });
    }

    // ── Langue ─────────────────────────────────────────────────────────

    public function test_the_mail_follows_the_account_locale(): void
    {
        Mail::fake();

        $member = $this->member();
        $member->update(['locale' => 'fr']);
        $this->preference($member);
        $this->overdueIssueFor($member);

        $this->runJobFor($member);

        Mail::assertSent(DigestMail::class, function (DigestMail $mail): bool {
            return $mail->locale === 'fr'
                && $mail->render() === (new DigestMail(
                    $mail->content,
                    $mail->context,
                ))->locale('fr')->render();
        });
    }

    public function test_the_mailable_renders_every_section(): void
    {
        $member = $this->member();
        $preference = $this->preference($member);
        $late = $this->overdueIssueFor($member);

        $context = new DigestContext(
            user: $member,
            workspace: $this->workspace,
            preference: $preference,
            now: CarbonImmutable::instance(now()),
        );
        $content = app(BuildDigest::class)->handle($context);

        $html = (new DigestMail($content, $context))->render();

        $this->assertStringContainsString($this->workspace->name, $html);
        $this->assertStringContainsString($late->title, $html);
        $this->assertStringContainsString(__('mail.digest.sections.overdue'), $html);
        $this->assertStringContainsString(__('mail.digest.sections.workload'), $html);
    }

    // ── La commande ────────────────────────────────────────────────────

    public function test_the_command_dispatches_one_job_per_due_account_without_mailing(): void
    {
        Bus::fake();
        Mail::fake();

        $due = $this->member();
        $this->preference($due);
        $this->overdueIssueFor($due);

        $weeklyOnMonday = $this->member();
        $this->preference($weeklyOnMonday, ['frequency' => DigestFrequency::Weekly]);

        $optedOut = $this->member();
        NotificationPreference::factory()->for($optedOut)->create(['timezone' => 'Europe/Paris']);

        $elsewhere = $this->member();
        $this->preference($elsewhere, ['timezone' => 'Pacific/Auckland']);

        $this->artisan('notifications:send-digests')->assertSuccessful();

        Bus::assertDispatchedTimes(SendAccountDigest::class, 2);
        Bus::assertDispatched(SendAccountDigest::class, fn (SendAccountDigest $job) => $job->user->is($due));
        Bus::assertDispatched(SendAccountDigest::class, fn (SendAccountDigest $job) => $job->user->is($weeklyOnMonday));
        Bus::assertNotDispatched(SendAccountDigest::class, fn (SendAccountDigest $job) => $job->user->is($optedOut));
        Bus::assertNotDispatched(SendAccountDigest::class, fn (SendAccountDigest $job) => $job->user->is($elsewhere));

        Mail::assertNothingSent();
    }

    public function test_the_command_is_scheduled_hourly(): void
    {
        $events = collect(Schedule::events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'notifications:send-digests'));

        $this->assertCount(1, $events);
        $this->assertSame('0 * * * *', $events->first()->expression);
    }

    public function test_the_selection_returns_the_due_account_ids(): void
    {
        $due = $this->preference($this->member());

        $this->assertSame(
            [$due->user_id],
            SendDueDigests::run(CarbonImmutable::instance(now()))->all(),
        );
    }

    // ── Garde-fou de type ──────────────────────────────────────────────

    public function test_an_empty_content_is_recognised_by_the_job(): void
    {
        $empty = new DigestContent(
            overdueIssues: [],
            upcomingIssues: [],
            newAssignments: [],
            newComments: [],
            workload: [],
            managerHighlights: [],
        );

        $this->assertTrue($empty->isEmpty());
    }
}

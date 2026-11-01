<?php

use App\Actions\Issues\CreateIssue;
use App\Actions\Issues\SetAssignees;
use App\Actions\Notifications\SendDueDigests;
use App\Actions\Projects\CreateProject;
use App\Jobs\SendAccountDigest;
use App\Mail\DigestMail;
use App\Models\Issue;
use App\Models\NotificationPreference;
use App\Models\Project;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

/**
 * Digest delivery seen from the browser side: the hourly selection picks the
 * opted-in account whose local clock hits 08:00, the job aggregates, mails
 * and stamps last_digest_at. The queue runs sync in tests and the mailer is
 * the array transport, so nothing ever leaves the process.
 */
function seedDigestWorkspace(): array
{
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);

    $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);

    return [$owner, $workspace, $project];
}

/**
 * An open issue due two days ago, assigned to the account: it lands in the
 * overdue section of the digest.
 */
function seedOverdueIssueFor(Workspace $workspace, Project $project, User $user): Issue
{
    app(WorkspaceContext::class)->set($workspace);

    $status = Status::withoutWorkspaceScope()
        ->where('workspace_id', $workspace->id)
        ->where('category', 'in_progress')
        ->firstOrFail();

    $issue = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Facture en retard',
        'status_id' => $status->id,
        'due_date' => CarbonImmutable::now('Europe/Paris')->subDays(2)->toDateString(),
    ]);

    SetAssignees::run($issue, [$user->id]);

    return $issue;
}

/**
 * Run the hourly selection at 08:00 Paris then the queued job synchronously,
 * exactly like the scheduler does in production.
 */
function runDueDigests(): void
{
    $due = SendDueDigests::run(CarbonImmutable::now('Europe/Paris')->setTime(8, 0));

    User::query()
        ->whereIn('id', $due->all())
        ->get()
        ->each(fn (User $user) => SendAccountDigest::dispatchSync($user));
}

it('test_digest_mail_is_sent_to_opted_in_account', function () {
    [$owner, $workspace, $project] = seedDigestWorkspace();
    NotificationPreference::factory()->for($owner)->enabled()->create([
        'frequency' => 'daily',
        'timezone' => 'Europe/Paris',
    ]);
    $issue = seedOverdueIssueFor($workspace, $project, $owner);
    Mail::fake();

    runDueDigests();

    Mail::assertSent(DigestMail::class, fn (DigestMail $mail) => $mail->hasTo($owner->email));

    app(WorkspaceContext::class)->set($workspace);
    $preference = NotificationPreference::query()
        ->where('user_id', $owner->id)
        ->firstOrFail();

    expect($preference->last_digest_at)->not->toBeNull()
        ->and($preference->last_digest_at->toDateString())
        ->toBe(CarbonImmutable::now('Europe/Paris')->toDateString());

    // The account is not selected twice the same local day.
    expect(SendDueDigests::run(CarbonImmutable::now('Europe/Paris')->setTime(9, 0)))->toBeEmpty();

    // The browser side of the same account still renders the saved state.
    $this->actingAs($owner);
    $page = visit('/settings/notifications');

    $page->assertSeeIn('[data-test="notification-timezone"]', 'Europe/Paris')
        ->assertNoSmoke();

    expect($issue->fresh()->title)->toBe('Facture en retard')
        ->and($issue->fresh()->assignees()->pluck('users.id')->all())->toBe([$owner->id]);
});

it('test_empty_digest_sends_no_mail', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    NotificationPreference::factory()->for($owner)->enabled()->create([
        'frequency' => 'daily',
        'timezone' => 'Europe/Paris',
    ]);
    Mail::fake();

    runDueDigests();

    Mail::assertNothingSent();

    app(WorkspaceContext::class)->set($workspace);
    $preference = NotificationPreference::query()
        ->where('user_id', $owner->id)
        ->firstOrFail();

    expect($preference->last_digest_at)->toBeNull();

    $this->actingAs($owner);
    $page = visit('/settings/notifications');

    $page->assertAttributeContains('[data-test="notification-opt-in"]', 'aria-checked', 'true')
        ->assertNoSmoke();
});

it('test_opted_out_account_receives_no_digest', function () {
    [$owner, $workspace, $project] = seedDigestWorkspace();
    NotificationPreference::factory()->for($owner)->create([
        'frequency' => 'daily',
        'timezone' => 'Europe/Paris',
    ]);
    seedOverdueIssueFor($workspace, $project, $owner);
    Mail::fake();

    runDueDigests();

    Mail::assertNothingSent();

    app(WorkspaceContext::class)->set($workspace);
    $preference = NotificationPreference::query()
        ->where('user_id', $owner->id)
        ->firstOrFail();

    expect($preference->enabled)->toBeFalse()
        ->and($preference->last_digest_at)->toBeNull();

    $this->actingAs($owner);
    $page = visit('/settings/notifications');

    $page->assertAttributeContains('[data-test="notification-opt-in"]', 'aria-checked', 'false')
        ->assertNoSmoke();
});

<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Issues\SetAssignees;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\DigestFrequency;
use App\Jobs\SendAccountDigest;
use App\Mail\DigestMail;
use App\Models\Invitation;
use App\Models\Issue;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The digest follows the account's active workspace — the one it last
 * activated in the web UI — not the first workspace by name. The column
 * is written when a workspace becomes active and read by the sending job,
 * which falls back to the historical first-by-name rule when the account
 * never activated one (seeded accounts, MCP-created accounts).
 */
class DigestActiveWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(User $user, string $name): Workspace
    {
        return CreateWorkspace::run($user, ['name' => $name]);
    }

    /**
     * An open issue due two days ago, assigned to the account, inside the
     * given workspace. The workspace context is bound for the fixture
     * writes, as every scoped action expects it.
     */
    private function overdueIssue(Workspace $workspace, User $user, string $title): Issue
    {
        app(WorkspaceContext::class)->set($workspace);

        $project = CreateProject::run($workspace, $user, ['name' => 'Projet '.$title]);
        $status = $workspace->statuses()->where('category', 'in_progress')->firstOrFail();

        $issue = CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => $title,
            'status_id' => $status->id,
            'due_date' => now()->subDays(2)->toDateString(),
        ]);

        SetAssignees::run($issue, [$user->id]);

        return $issue;
    }

    private function preference(User $user): NotificationPreference
    {
        return NotificationPreference::factory()->for($user)->enabled()->create([
            'frequency' => DigestFrequency::Daily,
            'timezone' => 'Europe/Paris',
        ]);
    }

    private function runDigest(User $user): void
    {
        SendAccountDigest::dispatchSync($user->fresh());
    }

    /**
     * @return list<string>
     */
    private function overdueIds(DigestMail $mail): array
    {
        return array_map(fn (array $row) => $row['id'], $mail->digest['overdueIssues']);
    }

    // ── Le job suit le workspace actif ─────────────────────────────────

    public function test_the_job_uses_the_current_workspace_of_the_account(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $alpha = $this->workspace($user, 'Alpha');
        $zulu = $this->workspace($user, 'Zulu');

        $alphaIssue = $this->overdueIssue($alpha, $user, 'Alpha late');
        $zuluIssue = $this->overdueIssue($zulu, $user, 'Zulu late');

        $this->preference($user);
        $user->forceFill(['current_workspace_id' => $zulu->id])->save();

        $this->runDigest($user);

        Mail::assertSent(DigestMail::class, function (DigestMail $mail) use ($zuluIssue, $alphaIssue): bool {
            return $mail->digest['workspaceName'] === 'Zulu'
                && $this->overdueIds($mail) === [$zuluIssue->id]
                && ! in_array($alphaIssue->id, $this->overdueIds($mail), true);
        });
    }

    public function test_the_job_falls_back_to_the_first_workspace_when_none_is_current(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $alpha = $this->workspace($user, 'Alpha');
        $this->workspace($user, 'Zulu');

        $alphaIssue = $this->overdueIssue($alpha, $user, 'Alpha late');
        $this->preference($user);

        $this->assertNull($user->fresh()->current_workspace_id);

        $this->runDigest($user);

        Mail::assertSent(DigestMail::class, function (DigestMail $mail) use ($alphaIssue): bool {
            return $mail->digest['workspaceName'] === 'Alpha'
                && $this->overdueIds($mail) === [$alphaIssue->id];
        });
    }

    public function test_the_job_ignores_a_stale_current_workspace(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $mine = $this->workspace($user, 'Mine');
        $mineIssue = $this->overdueIssue($mine, $user, 'Mine late');

        // A workspace the account is no longer a member of: the column is
        // stale (membership removed, not the workspace deleted).
        $foreign = CreateWorkspace::run(User::factory()->create(), ['name' => 'Aaa']);
        $user->forceFill(['current_workspace_id' => $foreign->id])->save();

        $this->preference($user);

        $this->runDigest($user);

        Mail::assertSent(DigestMail::class, function (DigestMail $mail) use ($mineIssue): bool {
            return $mail->digest['workspaceName'] === 'Mine'
                && $this->overdueIds($mail) === [$mineIssue->id];
        });
    }

    // ── La colonne suit le workspace actif ─────────────────────────────

    public function test_the_middleware_persists_the_resolved_workspace_on_the_account(): void
    {
        $user = User::factory()->create();
        $this->workspace($user, 'Alpha');
        $zulu = $this->workspace($user, 'Zulu');

        $this->actingAs($user)
            ->withSession(['workspace_id' => $zulu->id])
            ->get('/')
            ->assertOk();

        $this->assertSame($zulu->id, $user->fresh()->current_workspace_id);
    }

    public function test_switching_workspace_updates_the_current_workspace(): void
    {
        $user = User::factory()->create();
        $alpha = $this->workspace($user, 'Alpha');
        $zulu = $this->workspace($user, 'Zulu');
        $user->forceFill(['current_workspace_id' => $alpha->id])->save();

        $this->actingAs($user)
            ->post("/workspaces/{$zulu->id}/switch")
            ->assertRedirect();

        $this->assertSame($zulu->id, $user->fresh()->current_workspace_id);
    }

    public function test_creating_a_workspace_makes_it_current(): void
    {
        $user = User::factory()->create();
        $alpha = $this->workspace($user, 'Alpha');
        $user->forceFill(['current_workspace_id' => $alpha->id])->save();

        $this->actingAs($user)
            ->post('/workspaces', ['name' => 'Nouveau'])
            ->assertRedirect(route('home'));

        $created = Workspace::query()->where('name', 'Nouveau')->firstOrFail();

        $this->assertSame($created->id, $user->fresh()->current_workspace_id);
    }

    public function test_accepting_an_invitation_makes_the_workspace_current(): void
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'Studio Nova']);
        $member = User::factory()->create(['email' => 'member@test.dev']);

        $invitation = Invitation::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'email' => 'member@test.dev',
            'token' => Str::random(64),
            'expires_at' => now()->addDays(7),
        ]);

        $this->actingAs($member)
            ->get("/invitations/{$invitation->token}/accept")
            ->assertRedirect();

        $this->assertSame($workspace->id, $member->fresh()->current_workspace_id);
    }

    public function test_the_current_workspace_is_cleared_when_the_workspace_is_deleted(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspace($user, 'Temporary');
        $user->forceFill(['current_workspace_id' => $workspace->id])->save();

        $workspace->delete();

        $this->assertNull($user->fresh()->current_workspace_id);
    }
}

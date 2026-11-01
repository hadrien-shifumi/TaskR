<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Issues\SetAssignees;
use App\Actions\Notifications\BuildDigest;
use App\Actions\Projects\CreateProject;
use App\Actions\TimeTracking\CreateTimeEntry;
use App\Actions\TimeTracking\SetIssueEstimate;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\DigestFrequency;
use App\Enums\ProjectRole;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\NotificationPreference;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Presenters\Notifications\DigestPresenter;
use App\Support\DigestContext;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DigestAggregationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private Project $project;

    /** @var array<string, Status> */
    private array $statuses = [];

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00:00', 'Europe/Paris'));

        $this->owner = User::factory()->create();
        $this->workspace = CreateWorkspace::run($this->owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($this->workspace);

        $this->project = CreateProject::run($this->workspace, $this->owner, ['name' => 'Site vitrine']);

        foreach ($this->workspace->statuses as $status) {
            $this->statuses[$status->category->value] = $status;
        }

        $this->now = CarbonImmutable::instance(now());
    }

    private function context(User $user, DigestFrequency $frequency = DigestFrequency::Daily, ?CarbonImmutable $since = null, int $thresholdDays = 7): DigestContext
    {
        $preference = NotificationPreference::factory()->for($user)->enabled()->make([
            'frequency' => $frequency,
            'upcoming_threshold_days' => $thresholdDays,
            'timezone' => 'Europe/Paris',
        ]);

        return new DigestContext(
            user: $user,
            workspace: $this->workspace,
            preference: $preference,
            now: $this->now,
            since: $since,
        );
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

    private function projectMember(Project $project, User $user, ProjectRole $role = ProjectRole::Member): void
    {
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'role' => $role->value,
        ]);
    }

    private function digest(DigestContext $context): array
    {
        return app(BuildDigest::class)->handle($context)->toArray();
    }

    /**
     * @return array<int, string>
     */
    private function ids(array $rows): array
    {
        return array_map(fn (array $row) => $row['id'], $rows);
    }

    // ── Retards ────────────────────────────────────────────────────────

    public function test_overdue_issues_are_listed_with_their_days_late(): void
    {
        $member = $this->member();
        $late = $this->issue(['due_date' => now()->subDays(3)->toDateString()], category: 'in_progress');
        SetAssignees::run($late, [$member->id]);

        $digest = $this->digest($this->context($member));

        $this->assertCount(1, $digest['overdueIssues']);
        $this->assertSame($late->id, $digest['overdueIssues'][0]['id']);
        $this->assertSame(3, $digest['overdueIssues'][0]['daysLate']);
    }

    public function test_done_and_cancelled_issues_are_never_overdue(): void
    {
        $member = $this->member();
        $done = $this->issue(['due_date' => now()->subDays(3)->toDateString()], category: 'done');
        $cancelled = $this->issue(['due_date' => now()->subDays(3)->toDateString()], category: 'cancelled');
        SetAssignees::run($done, [$member->id]);
        SetAssignees::run($cancelled, [$member->id]);

        $digest = $this->digest($this->context($member));

        $this->assertSame([], $digest['overdueIssues']);
    }

    public function test_issues_assigned_to_someone_else_are_not_in_my_overdue_list(): void
    {
        $member = $this->member();
        $other = $this->member();
        $late = $this->issue(['due_date' => now()->subDays(2)->toDateString()]);
        SetAssignees::run($late, [$other->id]);

        $digest = $this->digest($this->context($member));

        $this->assertSame([], $digest['overdueIssues']);
    }

    // ── Échéances proches ──────────────────────────────────────────────

    public function test_upcoming_issues_are_limited_to_the_threshold(): void
    {
        $member = $this->member();
        $soon = $this->issue(['due_date' => now()->addDays(3)->toDateString()]);
        $far = $this->issue(['due_date' => now()->addDays(20)->toDateString()]);
        SetAssignees::run($soon, [$member->id]);
        SetAssignees::run($far, [$member->id]);

        $digest = $this->digest($this->context($member, thresholdDays: 7));

        $this->assertSame([$soon->id], $this->ids($digest['upcomingIssues']));
        $this->assertSame(3, $digest['upcomingIssues'][0]['daysUntilDue']);
    }

    public function test_the_threshold_is_configurable(): void
    {
        $member = $this->member();
        $soon = $this->issue(['due_date' => now()->addDays(3)->toDateString()]);
        $later = $this->issue(['due_date' => now()->addDays(20)->toDateString()]);
        SetAssignees::run($soon, [$member->id]);
        SetAssignees::run($later, [$member->id]);

        $digest = $this->digest($this->context($member, thresholdDays: 30));

        $this->assertEqualsCanonicalizing(
            [$soon->id, $later->id],
            $this->ids($digest['upcomingIssues']),
        );
    }

    public function test_an_issue_due_today_is_upcoming_not_overdue(): void
    {
        $member = $this->member();
        $today = $this->issue(['due_date' => now()->toDateString()]);
        SetAssignees::run($today, [$member->id]);

        $digest = $this->digest($this->context($member));

        $this->assertSame([], $digest['overdueIssues']);
        $this->assertSame([$today->id], $this->ids($digest['upcomingIssues']));
        $this->assertSame(0, $digest['upcomingIssues'][0]['daysUntilDue']);
    }

    // ── Nouvelles assignations ─────────────────────────────────────────

    public function test_a_recent_assignment_is_listed_and_an_older_one_is_not(): void
    {
        $member = $this->member();
        $recent = $this->issue();
        $old = $this->issue();

        $this->travelTo($this->now->subDays(5));
        SetAssignees::run($old, [$member->id]);

        $this->travelTo($this->now);
        SetAssignees::run($recent, [$member->id]);

        $digest = $this->digest($this->context($member));

        $this->assertSame([$recent->id], $this->ids($digest['newAssignments']));
    }

    public function test_assignments_are_filtered_by_the_previous_digest_date(): void
    {
        $member = $this->member();
        $issue = $this->issue();

        $this->travelTo($this->now->subDays(3));
        SetAssignees::run($issue, [$member->id]);
        $this->travelTo($this->now);

        $this->assertSame(
            [],
            $this->digest($this->context($member, since: $this->now->subDay()))['newAssignments'],
        );
        $this->assertSame(
            [$issue->id],
            $this->ids($this->digest($this->context($member, DigestFrequency::Weekly))['newAssignments']),
        );
    }

    public function test_reassigning_an_issue_keeps_the_original_assignment_date(): void
    {
        $member = $this->member();
        $issue = $this->issue();

        $this->travelTo($this->now->subDays(5));
        SetAssignees::run($issue, [$member->id]);

        $this->travelTo($this->now);
        SetAssignees::run($issue, [$member->id]);

        $this->assertSame(
            $this->now->subDays(5)->toDateTimeString(),
            DB::table('issue_assignees')
                ->where('issue_id', $issue->id)
                ->where('user_id', $member->id)
                ->value('assigned_at'),
        );
    }

    public function test_closed_issues_are_not_listed_as_new_assignments(): void
    {
        $member = $this->member();
        $done = $this->issue(category: 'done');
        SetAssignees::run($done, [$member->id]);

        $this->assertSame([], $this->digest($this->context($member))['newAssignments']);
    }

    // ── Nouveaux commentaires ──────────────────────────────────────────

    public function test_recent_comments_on_assigned_and_created_issues_are_listed(): void
    {
        $member = $this->member();
        $author = $this->member();

        $assigned = $this->issue();
        SetAssignees::run($assigned, [$member->id]);
        $created = $this->issue(['created_by' => $member->id]);

        $assignedComment = Comment::factory()->for($assigned)->for($author, 'author')->create();
        $createdComment = Comment::factory()->for($created)->for($author, 'author')->create();

        $digest = $this->digest($this->context($member));

        $this->assertEqualsCanonicalizing(
            [$assignedComment->id, $createdComment->id],
            $this->ids($digest['newComments']),
        );
    }

    public function test_my_own_comments_are_excluded(): void
    {
        $member = $this->member();
        $issue = $this->issue(['created_by' => $member->id]);
        Comment::factory()->for($issue)->for($member, 'author')->create();

        $this->assertSame([], $this->digest($this->context($member))['newComments']);
    }

    public function test_comments_outside_the_window_are_excluded(): void
    {
        $member = $this->member();
        $author = $this->member();
        $issue = $this->issue(['created_by' => $member->id]);

        $this->travelTo($this->now->subDays(4));
        Comment::factory()->for($issue)->for($author, 'author')->create();
        $this->travelTo($this->now);
        $recent = Comment::factory()->for($issue)->for($author, 'author')->create();

        $this->assertSame([$recent->id], $this->ids($this->digest($this->context($member))['newComments']));
    }

    public function test_comments_on_issues_i_do_not_follow_are_excluded(): void
    {
        $member = $this->member();
        $author = $this->member();
        $issue = $this->issue(['created_by' => $author->id]);
        Comment::factory()->for($issue)->for($author, 'author')->create();

        $this->assertSame([], $this->digest($this->context($member))['newComments']);
    }

    // ── Charge ─────────────────────────────────────────────────────────

    public function test_remaining_workload_is_the_estimate_minus_the_logged_hours(): void
    {
        $member = $this->member();
        $issue = $this->issue(category: 'in_progress');
        SetAssignees::run($issue, [$member->id]);
        SetIssueEstimate::run($issue, 10.0);
        CreateTimeEntry::run($issue, $member, ['hours' => 4.0]);

        $digest = $this->digest($this->context($member));

        $this->assertSame(1, $digest['workload']['openIssues']);
        $this->assertSame(10.0, $digest['workload']['estimatedHours']);
        $this->assertSame(4.0, $digest['workload']['loggedHours']);
        $this->assertSame(
            6.0,
            $digest['workload']['estimatedHours'] - $digest['workload']['loggedHours'],
        );
        $this->assertSame($member->id, $digest['workload']['userId']);
    }

    public function test_finished_issues_are_excluded_from_the_workload(): void
    {
        $member = $this->member();
        $done = $this->issue(category: 'done');
        SetAssignees::run($done, [$member->id]);
        SetIssueEstimate::run($done, 100.0);
        CreateTimeEntry::run($done, $member, ['hours' => 100.0]);

        $digest = $this->digest($this->context($member));

        $this->assertSame(0, $digest['workload']['openIssues']);
        $this->assertSame(0.0, $digest['workload']['estimatedHours']);
        $this->assertSame(0.0, $digest['workload']['loggedHours']);
    }

    // ── KPI manager ────────────────────────────────────────────────────

    public function test_a_contributor_gets_no_manager_highlights(): void
    {
        $member = $this->member();
        $this->projectMember($this->project, $member, ProjectRole::Member);
        $late = $this->issue(['due_date' => now()->subDays(2)->toDateString()]);
        SetAssignees::run($late, [$member->id]);

        $digest = $this->digest($this->context($member));

        $this->assertSame([], $digest['managerHighlights']);
        $this->assertArrayNotHasKey('managerHighlights', $digest['workload']);
    }

    public function test_a_project_manager_gets_the_highlights(): void
    {
        $manager = $this->member();
        $this->projectMember($this->project, $manager, ProjectRole::Manager);
        $late = $this->issue(['due_date' => now()->subDays(2)->toDateString()]);
        SetAssignees::run($late, [$manager->id]);

        $digest = $this->digest($this->context($manager));

        $this->assertCount(1, $digest['managerHighlights']);
        $this->assertSame($this->project->id, $digest['managerHighlights'][0]['projectId']);
        $this->assertSame(1, $digest['managerHighlights'][0]['overdueIssues']);
        $this->assertSame(0.0, $digest['managerHighlights'][0]['completionPercentage']);
        $this->assertNull($digest['managerHighlights'][0]['expectedPercentage']);
        $this->assertSame(0.0, $digest['managerHighlights'][0]['gap']);
    }

    public function test_a_guest_manager_is_downgraded_to_contributor(): void
    {
        $guest = $this->member('guest');
        $this->projectMember($this->project, $guest, ProjectRole::Manager);
        $late = $this->issue(['due_date' => now()->subDays(2)->toDateString()]);
        SetAssignees::run($late, [$guest->id]);

        $digest = $this->digest($this->context($guest));

        $this->assertSame([], $digest['managerHighlights']);
        $this->assertCount(1, $digest['overdueIssues']);
    }

    public function test_a_guest_only_sees_its_roster_perimeter(): void
    {
        $guest = $this->member('guest');
        $inScope = CreateProject::run($this->workspace, $this->owner, ['name' => 'Alpha']);
        $outOfScope = CreateProject::run($this->workspace, $this->owner, ['name' => 'Beta']);
        $this->projectMember($inScope, $guest);

        $visible = $this->issue(['due_date' => now()->subDay()->toDateString()], $inScope);
        $hidden = $this->issue(['due_date' => now()->subDay()->toDateString()], $outOfScope);
        SetAssignees::run($visible, [$guest->id]);
        SetAssignees::run($hidden, [$guest->id]);

        $digest = $this->digest($this->context($guest));

        $this->assertSame([$visible->id], $this->ids($digest['overdueIssues']));
    }

    public function test_a_guest_with_an_empty_roster_gets_an_empty_digest(): void
    {
        $guest = $this->member('guest');
        $late = $this->issue(['due_date' => now()->subDay()->toDateString()]);
        SetAssignees::run($late, [$guest->id]);

        $digest = $this->digest($this->context($guest));

        $this->assertSame([], $digest['overdueIssues']);
        $this->assertSame([], $digest['upcomingIssues']);
        $this->assertSame([], $digest['newAssignments']);
        $this->assertSame([], $digest['newComments']);
        $this->assertSame(0, $digest['workload']['openIssues']);
        $this->assertSame(0.0, $digest['workload']['estimatedHours']);
    }

    // ── Périmètre workspace ────────────────────────────────────────────

    public function test_another_workspace_is_never_aggregated(): void
    {
        $member = $this->member();
        $late = $this->issue(['due_date' => now()->subDay()->toDateString()]);
        SetAssignees::run($late, [$member->id]);

        $otherOwner = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($otherOwner, ['name' => 'Other']);
        app(WorkspaceContext::class)->set($otherWorkspace);
        $otherProject = CreateProject::run($otherWorkspace, $otherOwner, ['name' => 'Autre']);
        $otherStatus = $otherWorkspace->statuses()->where('category', 'backlog')->firstOrFail();
        $foreign = CreateIssue::run($otherWorkspace, [
            'project_id' => $otherProject->id,
            'title' => 'Foreign',
            'status_id' => $otherStatus->id,
            'due_date' => now()->subDay()->toDateString(),
        ]);
        SetAssignees::run($foreign, [$member->id]);

        app(WorkspaceContext::class)->set($this->workspace);
        $digest = $this->digest($this->context($member));

        $this->assertSame([$late->id], $this->ids($digest['overdueIssues']));
    }

    public function test_archived_projects_are_excluded(): void
    {
        $member = $this->member();
        $archived = CreateProject::run($this->workspace, $this->owner, ['name' => 'Old']);
        $late = $this->issue(['due_date' => now()->subDay()->toDateString()], $archived);
        SetAssignees::run($late, [$member->id]);
        $archived->update(['archived_at' => now()]);

        $this->assertSame([], $this->digest($this->context($member))['overdueIssues']);
    }

    // ── Contenu / isEmpty ──────────────────────────────────────────────

    public function test_a_digest_without_anything_to_report_is_empty(): void
    {
        $member = $this->member();

        $content = app(BuildDigest::class)->handle($this->context($member));

        $this->assertTrue($content->isEmpty());
    }

    public function test_a_digest_with_an_overdue_issue_is_not_empty(): void
    {
        $member = $this->member();
        $late = $this->issue(['due_date' => now()->subDay()->toDateString()]);
        SetAssignees::run($late, [$member->id]);

        $this->assertFalse(app(BuildDigest::class)->handle($this->context($member))->isEmpty());
    }

    public function test_building_the_digest_never_touches_the_last_digest_date(): void
    {
        $member = $this->member();
        $preference = NotificationPreference::factory()->for($member)->enabled()->create([
            'last_digest_at' => $this->now->subDay(),
        ]);
        $late = $this->issue(['due_date' => now()->subDay()->toDateString()]);
        SetAssignees::run($late, [$member->id]);

        app(BuildDigest::class)->handle($this->context($member));

        $this->assertSame(
            $this->now->subDay()->toDateTimeString(),
            $preference->fresh()->last_digest_at->toDateTimeString(),
        );
    }

    // ── Présentation ───────────────────────────────────────────────────

    public function test_the_presenter_exposes_the_payload_with_its_window_metadata(): void
    {
        $member = $this->member();
        $late = $this->issue(['due_date' => now()->subDay()->toDateString()]);
        SetAssignees::run($late, [$member->id]);

        $context = $this->context($member, thresholdDays: 14);

        $payload = DigestPresenter::present(app(BuildDigest::class)->handle($context), $context);

        $this->assertFalse($payload['isEmpty']);
        $this->assertSame($this->now->subDay()->toISOString(), $payload['windowStart']);
        $this->assertSame($this->now->toISOString(), $payload['generatedAt']);
        $this->assertSame(14, $payload['thresholdDays']);
        $this->assertSame('Europe/Paris', $payload['timezone']);
        $this->assertSame(1, $payload['overdueIssues'][0]['daysLate']);
        $this->assertArrayHasKey('workload', $payload);
        $this->assertArrayHasKey('managerHighlights', $payload);
    }

    // ── Migration ──────────────────────────────────────────────────────

    public function test_the_migration_backfills_existing_assignments_with_the_issue_creation_date(): void
    {
        $member = $this->member();
        $issue = $this->issue();

        $this->travelTo($this->now->subDays(10));
        DB::table('issue_assignees')->insert([
            'issue_id' => $issue->id,
            'user_id' => $member->id,
            'assigned_at' => null,
        ]);
        $this->travelTo($this->now);

        $migration = require database_path('migrations/2026_11_01_000100_add_assigned_at_to_issue_assignees_table.php');
        DB::statement(
            'UPDATE issue_assignees SET assigned_at = (SELECT created_at FROM issues WHERE issues.id = issue_assignees.issue_id)'
        );

        $this->assertSame(
            $issue->created_at->toDateTimeString(),
            DB::table('issue_assignees')->where('issue_id', $issue->id)->value('assigned_at'),
        );
        $this->assertIsObject($migration);
    }

    // ── Fuseau du compte ───────────────────────────────────────────────

    public function test_date_bounds_follow_the_account_timezone(): void
    {
        $member = $this->member();
        // 2026-11-01 09:00 Paris = 08:00 UTC: "today" in Paris is the 1st.
        $dueToday = $this->issue(['due_date' => '2026-11-01']);
        SetAssignees::run($dueToday, [$member->id]);

        $digest = $this->digest($this->context($member));

        $this->assertSame([], $digest['overdueIssues']);
        $this->assertSame([$dueToday->id], $this->ids($digest['upcomingIssues']));
    }
}

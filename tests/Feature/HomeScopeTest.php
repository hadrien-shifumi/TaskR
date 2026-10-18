<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Issues\SetAssignees;
use App\Actions\Projects\ArchiveProject;
use App\Actions\Projects\CreateProject;
use App\Actions\TimeTracking\CreateTimeEntry;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\ProjectRole;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class HomeScopeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace, 2: Project, 3: Project, 4: User, 5: array<string, Status>}
     */
    private function setupScopedGuest(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $inScope = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $outOfScope = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

        $guest = User::factory()->create();
        Membership::create([
            'user_id' => $guest->id,
            'workspace_id' => $workspace->id,
            'role' => 'guest',
        ]);
        ProjectMember::create([
            'project_id' => $inScope->id,
            'user_id' => $guest->id,
            'role' => 'member',
        ]);

        $statuses = [];
        foreach ($workspace->statuses as $status) {
            $statuses[$status->category->value] = $status;
        }

        return [$owner, $workspace, $inScope, $outOfScope, $guest, $statuses];
    }

    private function createIssue(Project $project, Status $status, string $title): Issue
    {
        return CreateIssue::run(app(WorkspaceContext::class)->getOrFail(), [
            'project_id' => $project->id,
            'title' => $title,
            'status_id' => $status->id,
        ]);
    }

    // ── Home : compteurs scopés au périmètre ──────────────────────────

    public function test_guest_home_counters_count_only_scope_projects(): void
    {
        [$owner, $workspace, $inScope, $outOfScope, $guest, $statuses] = $this->setupScopedGuest();

        // Alpha : 2 ouverts (1 en retard) + 1 done. Beta (hors périmètre) : 1 ouvert en retard.
        $this->createIssue($inScope, $statuses['backlog'], 'A1');
        $this->createIssue($inScope, $statuses['in_progress'], 'A2');
        Issue::query()->where('title', 'A2')->update(['due_date' => now()->subDay()->toDateString()]);
        $this->createIssue($inScope, $statuses['done'], 'A3');
        $this->createIssue($outOfScope, $statuses['in_progress'], 'B1');
        Issue::query()->where('title', 'B1')->update(['due_date' => now()->subDay()->toDateString()]);

        $this->actingAs($guest)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Home')
                ->where('stats.totalIssues', 3)
                ->where('stats.openIssues', 2)
                ->where('stats.overdueIssues', 1));
    }

    public function test_guest_home_stats_hours_count_only_scope_time_entries(): void
    {
        [$owner, $workspace, $inScope, $outOfScope, $guest, $statuses] = $this->setupScopedGuest();

        $inScopeIssue = $this->createIssue($inScope, $statuses['in_progress'], 'A1');
        $outOfScopeIssue = $this->createIssue($outOfScope, $statuses['in_progress'], 'B1');

        CreateTimeEntry::run($inScopeIssue, $owner, ['hours' => 2.5]);
        CreateTimeEntry::run($outOfScopeIssue, $owner, ['hours' => 4.0]);

        $this->actingAs($guest)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stats.loggedHoursThisWeek', 2.5));
    }

    public function test_guest_home_lists_only_scope_project_cards(): void
    {
        [$owner, $workspace, $inScope, $outOfScope, $guest, $statuses] = $this->setupScopedGuest();

        $archivedInScope = CreateProject::run($workspace, $owner, ['name' => 'Gamma']);
        ProjectMember::create([
            'project_id' => $archivedInScope->id,
            'user_id' => $guest->id,
            'role' => 'member',
        ]);
        ArchiveProject::run($archivedInScope, $owner);

        $this->actingAs($guest)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stats.activeProjects', 1)
                ->where('stats.archivedProjects', 1)
                ->has('projects', 1)
                ->where('projects.0.id', $inScope->id)
                ->where('projects.0.name', 'Alpha'));
    }

    public function test_guest_home_my_issues_exclude_out_of_scope_assignments(): void
    {
        [$owner, $workspace, $inScope, $outOfScope, $guest, $statuses] = $this->setupScopedGuest();

        $inScopeIssue = $this->createIssue($inScope, $statuses['in_progress'], 'In scope');
        $residualIssue = $this->createIssue($outOfScope, $statuses['in_progress'], 'Residual');

        SetAssignees::run($inScopeIssue, [$guest->id]);
        SetAssignees::run($residualIssue, [$guest->id]);

        $this->actingAs($guest)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('myIssues', 1)
                ->where('myIssues.0.id', $inScopeIssue->id));
    }

    public function test_member_home_counters_remain_workspace_wide(): void
    {
        [$owner, $workspace, $inScope, $outOfScope, $guest, $statuses] = $this->setupScopedGuest();

        $this->createIssue($inScope, $statuses['backlog'], 'A1');
        $this->createIssue($outOfScope, $statuses['backlog'], 'B1');

        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        $this->actingAs($member)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stats.totalIssues', 2)
                ->where('stats.openIssues', 2)
                ->has('projects', 2));
    }

    // ── Page Health : vue « member » pour un guest ────────────────────

    public function test_guest_project_health_page_shows_member_view_only(): void
    {
        [$owner, $workspace, $inScope, $outOfScope, $guest, $statuses] = $this->setupScopedGuest();

        // Guest roster-manager du projet de son périmètre : la décision du
        // Owner ne lui ouvre pas les KPIs globaux.
        ProjectMember::query()
            ->where('project_id', $inScope->id)
            ->where('user_id', $guest->id)
            ->update(['role' => ProjectRole::Manager->value]);

        $mine = $this->createIssue($inScope, $statuses['in_progress'], 'Mine');
        SetAssignees::run($mine, [$guest->id]);
        $this->createIssue($inScope, $statuses['backlog'], 'Other');

        $this->actingAs($guest)
            ->get(route('projects.health', $inScope->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Health')
                ->where('canSeeGlobalKpis', false)
                ->where('health', null)
                ->has('teamWorkload', 0)
                ->has('lateIssues', 0)
                ->where('myWorkload.userId', $guest->id)
                ->where('myWorkload.openIssues', 1)
                ->where('myWorkload.totalIssues', 1));
    }

    public function test_project_manager_health_page_keeps_global_kpis(): void
    {
        [$owner, $workspace, $inScope, $outOfScope, $guest, $statuses] = $this->setupScopedGuest();

        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);
        ProjectMember::create([
            'project_id' => $inScope->id,
            'user_id' => $member->id,
            'role' => ProjectRole::Manager->value,
        ]);

        $this->createIssue($inScope, $statuses['backlog'], 'A1');

        $this->actingAs($member)
            ->get(route('projects.health', $inScope->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Health')
                ->where('canSeeGlobalKpis', true)
                ->where('health.totalIssues', 1));
    }
}

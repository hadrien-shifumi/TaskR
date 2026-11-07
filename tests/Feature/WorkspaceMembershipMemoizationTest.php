<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\WorkspaceRole;
use App\Models\Membership;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The workspace context resolves the acting user's role and project scope
 * many times per request (authorization, Inertia shares, listing scoping).
 * Each resolution must cost a single membership query per user: repeated
 * lookups ride an in-instance memo, reset when the context switches
 * workspaces.
 */
class WorkspaceMembershipMemoizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace];
    }

    private function addUser(Workspace $workspace, string $role): User
    {
        $user = User::factory()->create();
        Membership::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'role' => $role,
        ]);

        return $user;
    }

    /**
     * Count the single-row membership lookups ("find this user's role in
     * this workspace"), not the legitimate member listings, nor the
     * workspace resolution whose exists-subquery also touches the table
     * and ends with its own limit 1.
     */
    private function membershipLookupCount(): int
    {
        return collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql): bool => str_starts_with($sql, 'select * from "memberships"')
                && str_contains($sql, '"user_id"')
                && str_contains($sql, 'limit 1'))
            ->count();
    }

    private function rosterLookupCount(): int
    {
        return collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql): bool => str_contains($sql, 'from "project_members"')
                && str_contains($sql, '"user_id"'))
            ->count();
    }

    public function test_repeated_role_lookups_run_a_single_membership_query(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->assertSame(WorkspaceRole::Owner, $workspace->roleOf($owner));
        $this->assertSame(WorkspaceRole::Owner, $workspace->roleOf($owner));
        $this->assertTrue($workspace->hasMember($owner));
        $this->assertTrue($workspace->hasOwner($owner));
        $this->assertFalse($workspace->isGuest($owner));

        $this->assertSame(1, $this->membershipLookupCount());
    }

    public function test_membership_cache_is_isolated_per_user(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $guest = $this->addUser($workspace, 'guest');

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->assertSame(WorkspaceRole::Owner, $workspace->roleOf($owner));
        $this->assertSame(WorkspaceRole::Guest, $workspace->roleOf($guest));
        $this->assertSame(WorkspaceRole::Owner, $workspace->roleOf($owner));
        $this->assertSame(WorkspaceRole::Guest, $workspace->roleOf($guest));

        $this->assertSame(2, $this->membershipLookupCount());
    }

    public function test_accessible_project_ids_runs_a_single_membership_query_for_a_full_member(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $context = app(WorkspaceContext::class);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->assertNull($context->accessibleProjectIds($owner));
        $this->assertNull($context->accessibleProjectIds($owner));
        $this->assertNull($context->accessibleProjectIds($owner));

        $this->assertSame(1, $this->membershipLookupCount());
    }

    public function test_guest_roster_is_queried_once_for_accessible_project_ids(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);

        $guest = $this->addUser($workspace, 'guest');
        ProjectMember::create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'user_id' => $guest->id,
            'role' => 'member',
        ]);

        $context = app(WorkspaceContext::class);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $expected = [$project->id];
        $this->assertSame($expected, $context->accessibleProjectIds($guest));
        $this->assertSame($expected, $context->accessibleProjectIds($guest));
        $this->assertSame($expected, $context->accessibleProjectIds($guest));

        $this->assertSame(1, $this->membershipLookupCount());
        $this->assertSame(1, $this->rosterLookupCount());
    }

    public function test_accessible_scope_is_recomputed_after_a_workspace_switch(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $otherOwner = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($otherOwner, ['name' => 'Other']);
        $otherWorkspace->memberships()->create([
            'user_id' => $owner->getKey(),
            'role' => 'guest',
        ]);

        $context = app(WorkspaceContext::class);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->assertNull($context->accessibleProjectIds($owner));

        $context->set($otherWorkspace);
        $this->assertSame([], $context->accessibleProjectIds($owner));

        // The switch invalidated the memo: the second workspace's scope is
        // resolved again, never served from the first workspace's cache.
        $this->assertSame(2, $this->membershipLookupCount());
    }

    public function test_issues_page_performs_a_single_membership_lookup(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);

        foreach (['A', 'B'] as $title) {
            CreateIssue::run($workspace, [
                'project_id' => $project->id,
                'title' => $title,
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($owner)
            ->get('/issues')
            ->assertOk();

        // Authorization, Inertia shares and list scoping all need the role:
        // they must share one lookup, not one query each.
        $this->assertSame(1, $this->membershipLookupCount());
    }
}

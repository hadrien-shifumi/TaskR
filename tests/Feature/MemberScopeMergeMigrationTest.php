<?php

namespace Tests\Feature;

use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\ProjectRole;
use App\Models\Membership;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The merge migration converts the legacy member perimeter (the
 * membership_project rows plus the is_scoped marker) into project team
 * rows, then drops the legacy structures. These tests rebuild the
 * pre-merge structures on the migrated schema, populate the four
 * populations of the epic decision D4, run the migration object
 * directly (the precedent of the project_members data migration) and
 * verify that nobody loses, gains or sees a role downgraded.
 */
class MemberScopeMergeMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_FILE = '2026_11_15_091000_merge_member_scopes_into_project_teams.php';

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

    /**
     * Rebuild the legacy structures exactly as their create migrations
     * shaped them: the is_scoped marker on memberships and the
     * membership_project perimeter table.
     */
    private function recreateLegacyScopeStructures(): void
    {
        Schema::table('memberships', function (Blueprint $table): void {
            $table->boolean('is_scoped')->default(false);
        });

        Schema::create('membership_project', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('membership_id');
            $table->foreign('membership_id')->references('id')->on('memberships')->cascadeOnDelete();
            $table->ulid('project_id');
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['membership_id', 'project_id']);
        });
    }

    /**
     * Mark a membership as scoped and grant it the given perimeter, the
     * way the legacy pilotage wrote it: raw rows, since the legacy
     * models are gone.
     *
     * @param  array<int, string>  $projectIds
     */
    private function grantLegacyScope(Membership $membership, array $projectIds): void
    {
        DB::table('memberships')->where('id', $membership->id)->update(['is_scoped' => true]);

        foreach ($projectIds as $projectId) {
            DB::table('membership_project')->insert([
                'id' => (string) Str::ulid(),
                'membership_id' => $membership->id,
                'project_id' => $projectId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function runMergeMigration(): void
    {
        $migration = include database_path('migrations/'.self::MIGRATION_FILE);
        $migration->up();
    }

    public function test_unscoped_members_join_every_project_team_of_their_workspace(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $alpha = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $beta = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        $this->recreateLegacyScopeStructures();
        $this->runMergeMigration();

        $this->assertSame(2, ProjectMember::query()->where('user_id', $member->id)->count());
        $this->assertDatabaseHas('project_members', [
            'project_id' => $alpha->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);
        $this->assertDatabaseHas('project_members', [
            'project_id' => $beta->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);
    }

    public function test_scoped_members_join_exactly_their_perimeter(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $alpha = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $beta = CreateProject::run($workspace, $owner, ['name' => 'Beta']);
        CreateProject::run($workspace, $owner, ['name' => 'Gamma']);

        $member = User::factory()->create();
        $membership = Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        $this->recreateLegacyScopeStructures();
        $this->grantLegacyScope($membership, [$alpha->id, $beta->id]);
        $this->runMergeMigration();

        $joined = ProjectMember::query()->where('user_id', $member->id)->pluck('project_id')->all();
        sort($joined);
        $expected = [$alpha->id, $beta->id];
        sort($expected);
        $this->assertSame($expected, $joined);
    }

    public function test_guests_and_workspace_owner_receive_no_new_team_rows(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $alpha = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $beta = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

        // The owner already carries their creation Owner rows; a guest
        // carries their roster row — the merge must add nothing for them.
        $ownerRowCount = ProjectMember::query()->where('user_id', $owner->id)->count();

        $guest = User::factory()->create();
        Membership::create([
            'user_id' => $guest->id,
            'workspace_id' => $workspace->id,
            'role' => 'guest',
        ]);
        $beta->projectMembers()->create(['user_id' => $guest->id, 'role' => 'member']);
        $guestRowCount = ProjectMember::query()->where('user_id', $guest->id)->count();

        $this->recreateLegacyScopeStructures();
        $this->runMergeMigration();

        $this->assertSame($ownerRowCount, ProjectMember::query()->where('user_id', $owner->id)->count());
        $this->assertSame($guestRowCount, ProjectMember::query()->where('user_id', $guest->id)->count());
        $this->assertSame(
            [ProjectRole::Member],
            ProjectMember::query()->where('user_id', $guest->id)->pluck('role')->unique()->values()->all(),
        );
    }

    public function test_every_population_keeps_exactly_its_effective_access(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $alpha = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $beta = CreateProject::run($workspace, $owner, ['name' => 'Beta']);
        $gamma = CreateProject::run($workspace, $owner, ['name' => 'Gamma']);

        // Unscoped member: full workspace access before, a team row on
        // every project after — same effective access.
        $plain = User::factory()->create();
        Membership::create([
            'user_id' => $plain->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        // Scoped member: granted Alpha, and they own Delta (created by
        // themselves) — the old resolver granted perimeter + owned, the
        // roster carries exactly the same two projects after the merge.
        $scoped = User::factory()->create();
        $scopedMembership = Membership::create([
            'user_id' => $scoped->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);
        $delta = CreateProject::run($workspace, $scoped, ['name' => 'Delta']);

        // Guest: roster-driven before and after — untouched.
        $guest = User::factory()->create();
        Membership::create([
            'user_id' => $guest->id,
            'workspace_id' => $workspace->id,
            'role' => 'guest',
        ]);
        $beta->projectMembers()->create(['user_id' => $guest->id, 'role' => 'member']);

        // The pre-merge doctrine, pinned here as the expected access.
        $allProjectIds = [$alpha->id, $beta->id, $gamma->id, $delta->id];
        $scopedExpected = [$alpha->id, $delta->id];
        $guestExpected = [$beta->id];

        $this->recreateLegacyScopeStructures();
        $this->grantLegacyScope($scopedMembership, [$alpha->id]);
        $this->runMergeMigration();

        app(WorkspaceContext::class)->set($workspace);
        $context = app(WorkspaceContext::class);

        // The workspace owner stays unrestricted, with or without rows.
        $this->assertNull($context->accessibleProjectIds($owner));

        $plainScope = $context->accessibleProjectIds($plain);
        sort($plainScope);
        $allSorted = $allProjectIds;
        sort($allSorted);
        $this->assertSame($allSorted, $plainScope);

        $scopedScope = $context->accessibleProjectIds($scoped);
        sort($scopedScope);
        $scopedSorted = $scopedExpected;
        sort($scopedSorted);
        $this->assertSame($scopedSorted, $scopedScope);

        $guestScope = $context->accessibleProjectIds($guest);
        sort($guestScope);
        $guestSorted = $guestExpected;
        sort($guestSorted);
        $this->assertSame($guestSorted, $guestScope);
    }

    public function test_existing_roster_rows_are_never_duplicated_or_downgraded(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $alpha = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $beta = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

        // An unscoped member already manages Alpha by hand: the backfill
        // must not duplicate the row nor flatten the manual role.
        $plain = User::factory()->create();
        Membership::create([
            'user_id' => $plain->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);
        $alpha->projectMembers()->create(['user_id' => $plain->id, 'role' => 'manager']);

        // A scoped member granted Alpha already sits on its team as a
        // manual Member: same rule.
        $scoped = User::factory()->create();
        $scopedMembership = Membership::create([
            'user_id' => $scoped->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);
        $alpha->projectMembers()->create(['user_id' => $scoped->id, 'role' => 'member']);
        $beta->projectMembers()->create(['user_id' => $scoped->id, 'role' => 'manager']);

        $this->recreateLegacyScopeStructures();
        $this->grantLegacyScope($scopedMembership, [$alpha->id]);
        $this->runMergeMigration();

        $this->assertSame(
            ProjectRole::Manager,
            ProjectMember::query()->where('project_id', $alpha->id)->where('user_id', $plain->id)->value('role'),
        );
        $this->assertSame(1, ProjectMember::query()->where('project_id', $alpha->id)->where('user_id', $plain->id)->count());
        $this->assertSame(
            ProjectRole::Member,
            ProjectMember::query()->where('project_id', $beta->id)->where('user_id', $plain->id)->value('role'),
        );

        $this->assertSame(1, ProjectMember::query()->where('project_id', $alpha->id)->where('user_id', $scoped->id)->count());
        $this->assertSame(
            ProjectRole::Member,
            ProjectMember::query()->where('project_id', $alpha->id)->where('user_id', $scoped->id)->value('role'),
        );
        $this->assertSame(
            ProjectRole::Manager,
            ProjectMember::query()->where('project_id', $beta->id)->where('user_id', $scoped->id)->value('role'),
        );
    }

    public function test_scoped_member_with_empty_perimeter_gets_no_new_team_rows(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        CreateProject::run($workspace, $owner, ['name' => 'Alpha']);

        $scoped = User::factory()->create();
        $scopedMembership = Membership::create([
            'user_id' => $scoped->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        $this->recreateLegacyScopeStructures();
        $this->grantLegacyScope($scopedMembership, []);
        $this->runMergeMigration();

        $this->assertSame(0, ProjectMember::query()->where('user_id', $scoped->id)->count());

        app(WorkspaceContext::class)->set($workspace);
        $this->assertSame([], app(WorkspaceContext::class)->accessibleProjectIds($scoped));
    }

    public function test_migration_drops_the_legacy_structures(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        CreateProject::run($workspace, $owner, ['name' => 'Alpha']);

        $this->recreateLegacyScopeStructures();
        $this->runMergeMigration();

        $this->assertFalse(Schema::hasTable('membership_project'));
        $this->assertFalse(Schema::hasColumn('memberships', 'is_scoped'));
    }

    public function test_migration_is_a_no_op_without_the_legacy_structures(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $alpha = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);

        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        // Fresh installs and re-runs: the structures are already gone,
        // the migration must neither crash nor write anything.
        $this->runMergeMigration();
        $this->runMergeMigration();

        $this->assertSame(0, ProjectMember::query()->where('user_id', $member->id)->count());
        $this->assertTrue(Schema::hasTable('project_members'));
        $this->assertDatabaseHas('project_members', [
            'project_id' => $alpha->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);
    }

    public function test_backfill_stays_within_the_members_workspace(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $alpha = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);

        $otherOwner = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($otherOwner, ['name' => 'Other']);
        app(WorkspaceContext::class)->set($otherWorkspace);
        $foreign = CreateProject::run($otherWorkspace, $otherOwner, ['name' => 'Foreign']);
        app(WorkspaceContext::class)->set($workspace);

        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        $this->recreateLegacyScopeStructures();
        $this->runMergeMigration();

        $joined = ProjectMember::query()->where('user_id', $member->id)->pluck('project_id')->all();
        $this->assertSame([$alpha->id], $joined);
        $this->assertSame(
            0,
            ProjectMember::query()->where('project_id', $foreign->id)->where('user_id', $member->id)->count(),
        );
    }
}

<?php

namespace Tests\Feature;

use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Invitation;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The invitation_project pivot gains a project role column with the
 * "member" default: pending invitations at deployment convert naturally —
 * their announced projects land as plain members, exactly what their
 * acceptance produced before the rework.
 */
class InvitationProjectRoleMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_FILE = '2026_11_15_131000_add_role_to_invitation_project_table.php';

    /**
     * @return array{0: User, 1: object, 2: Invitation}
     */
    private function pendingInvitationWithLegacyRow(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);
        $alpha = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $beta = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

        $invitation = Invitation::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'email' => 'peer@example.com',
            'role' => 'guest',
            'token' => Str::random(64),
            'expires_at' => now()->addDays(7),
        ]);

        // Pre-deployment shape: the pivot carries no role column. The
        // rows are written raw, the way the legacy attachScope did.
        Schema::table('invitation_project', function (Blueprint $table): void {
            $table->dropColumn('role');
        });

        foreach ([$alpha, $beta] as $project) {
            DB::table('invitation_project')->insert([
                'id' => (string) Str::ulid(),
                'invitation_id' => $invitation->id,
                'project_id' => $project->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [$workspace, $alpha, $invitation];
    }

    private function runMigration(): void
    {
        $migration = include database_path('migrations/'.self::MIGRATION_FILE);
        $migration->up();
    }

    public function test_pending_invitation_projects_convert_to_the_member_default(): void
    {
        [, $alpha, $invitation] = $this->pendingInvitationWithLegacyRow();

        $this->runMigration();

        $this->assertTrue(Schema::hasColumn('invitation_project', 'role'));

        $rows = DB::table('invitation_project')
            ->where('invitation_id', $invitation->id)
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame(['member', 'member'], $rows->pluck('role')->all());
        $this->assertSame($alpha->id, $rows->first()->project_id);
    }

    public function test_the_role_column_drops_on_rollback(): void
    {
        $this->pendingInvitationWithLegacyRow();

        $migration = include database_path('migrations/'.self::MIGRATION_FILE);
        $migration->up();
        $this->assertTrue(Schema::hasColumn('invitation_project', 'role'));

        $migration->down();
        $this->assertFalse(Schema::hasColumn('invitation_project', 'role'));
    }
}

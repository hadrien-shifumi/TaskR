<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // The merge of the epic access rework: the project team becomes
        // the single source of project access, so the legacy member
        // perimeter — the membership_project rows plus the is_scoped
        // marker — is converted into team rows, then destroyed. The
        // conversion runs before the drops; guards keep the migration
        // replayable and harmless on fresh installs, where the legacy
        // structures are empty.
        $hasScopeTable = Schema::hasTable('membership_project');
        $hasScopedColumn = Schema::hasColumn('memberships', 'is_scoped');

        if ($hasScopeTable && $hasScopedColumn) {
            $this->backfillUnscopedMembers();
            $this->backfillScopedMembers();
        }

        Schema::dropIfExists('membership_project');

        if ($hasScopedColumn) {
            Schema::table('memberships', function (Blueprint $table): void {
                $table->dropColumn('is_scoped');
            });
        }
    }

    /**
     * D4 backfill, unscoped population: a member with the default full
     * workspace access joins every team of their workspace as a plain
     * contributor — their effective access is unchanged, now carried by
     * the roster instead of the implicit "sees everything".
     */
    private function backfillUnscopedMembers(): void
    {
        $rows = DB::table('memberships as m')
            ->join('projects as p', 'p.workspace_id', '=', 'm.workspace_id')
            ->where('m.role', 'member')
            ->where(function ($query): void {
                $query->where('m.is_scoped', false)->orWhereNull('m.is_scoped');
            })
            ->select('m.workspace_id', 'm.user_id', 'p.id as project_id')
            ->get();

        $this->insertTeamRows($rows);
    }

    /**
     * D4 backfill, scoped population: a member restricted to a perimeter
     * joins exactly the teams of that perimeter. Guests never carry the
     * scoped marker (their perimeter is the roster itself) and the
     * workspace owner is unrestricted by definition — neither match the
     * member filter, so both populations are left untouched.
     */
    private function backfillScopedMembers(): void
    {
        $rows = DB::table('memberships as m')
            ->join('membership_project as mp', 'mp.membership_id', '=', 'm.id')
            ->where('m.role', 'member')
            ->where('m.is_scoped', true)
            ->select('m.workspace_id', 'm.user_id', 'mp.project_id')
            ->get();

        $this->insertTeamRows($rows);
    }

    /**
     * Insert the converted rows as plain contributors. insertOrIgnore
     * honors the (project_id, user_id) uniqueness: a member already on a
     * team keeps their row — and their manually granted role — untouched.
     *
     * @param  Collection<int, object{workspace_id: string, user_id: int, project_id: string}>  $rows
     */
    private function insertTeamRows($rows): void
    {
        $rows
            ->map(static fn ($row): array => [
                'id' => (string) Str::ulid(),
                'workspace_id' => $row->workspace_id,
                'project_id' => $row->project_id,
                'user_id' => $row->user_id,
                'role' => 'member',
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->chunk(500)
            ->each(static function ($chunk): void {
                DB::table('project_members')->insertOrIgnore($chunk->all());
            });
    }

    public function down(): void
    {
        // One-way conversion: the perimeter data is not reconstructible
        // from the team rows. The structures alone are restored, so the
        // schema keeps matching the migrations that created them and the
        // rollback chain stays coherent.
        if (! Schema::hasColumn('memberships', 'is_scoped')) {
            Schema::table('memberships', function (Blueprint $table): void {
                $table->boolean('is_scoped')->default(false)->after('role');
            });
        }

        if (! Schema::hasTable('membership_project')) {
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
    }
};

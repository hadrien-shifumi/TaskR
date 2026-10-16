<?php

use App\Enums\ProjectRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // For each existing project, the workspace owner becomes the project owner.
        $projects = DB::table('projects')->get();

        foreach ($projects as $project) {
            $workspaceOwner = DB::table('memberships')
                ->where('workspace_id', $project->workspace_id)
                ->where('role', 'owner')
                ->first();

            if ($workspaceOwner === null) {
                continue;
            }

            $alreadyExists = DB::table('project_members')
                ->where('project_id', $project->id)
                ->where('user_id', $workspaceOwner->user_id)
                ->exists();

            if ($alreadyExists) {
                continue;
            }

            DB::table('project_members')->insert([
                'id' => Str::ulid()->toString(),
                'workspace_id' => $project->workspace_id,
                'project_id' => $project->id,
                'user_id' => $workspaceOwner->user_id,
                'role' => ProjectRole::Owner->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Data migration: no rollback (would lose roster data).
    }
};

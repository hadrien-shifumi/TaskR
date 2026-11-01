<?php

use App\Models\Project;
use App\Support\HandleGenerator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('handle', 32)->nullable();
        });

        // Backfill in PHP: handles are unique per workspace, so the
        // collision suffixes resolve within each workspace's rows.
        Project::withoutWorkspaceScope()
            ->whereNull('handle')
            ->orderBy('id')
            ->get()
            ->each(function (Project $project): void {
                $project->handle = HandleGenerator::forProject($project->name, (string) $project->workspace_id);
                $project->save();
            });

        Schema::table('projects', function (Blueprint $table) {
            $table->unique(['workspace_id', 'handle']);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'handle']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('handle');
        });
    }
};

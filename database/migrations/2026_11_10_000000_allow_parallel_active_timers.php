<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Parallel timers: the one-timer-per-user-per-workspace rule moves
        // from the database to the application (duplicate-on-the-same-issue
        // guard in StartTimer), so the unique constraint becomes a plain
        // lookup index.
        Schema::table('active_timers', function (Blueprint $table): void {
            $table->dropUnique('active_timers_user_workspace_unique');
            $table->index(['user_id', 'workspace_id'], 'active_timers_user_workspace_index');
        });
    }

    public function down(): void
    {
        Schema::table('active_timers', function (Blueprint $table): void {
            $table->dropIndex('active_timers_user_workspace_index');
            $table->unique(['user_id', 'workspace_id'], 'active_timers_user_workspace_unique');
        });
    }
};

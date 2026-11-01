<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Assignment date, needed by the notifications digest ("new
        // assignments since the last digest"). Existing rows are backfilled
        // with the issue creation date: the best available approximation.
        Schema::table('issue_assignees', function (Blueprint $table) {
            $table->timestamp('assigned_at')->nullable();
            $table->index('assigned_at');
        });

        DB::statement(
            'UPDATE issue_assignees SET assigned_at = (SELECT created_at FROM issues WHERE issues.id = issue_assignees.issue_id)'
        );
    }

    public function down(): void
    {
        Schema::table('issue_assignees', function (Blueprint $table) {
            $table->dropIndex(['assigned_at']);
            $table->dropColumn('assigned_at');
        });
    }
};

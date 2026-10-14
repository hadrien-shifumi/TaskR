<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->ulid('issue_id');
            $table->foreign('issue_id')->references('id')->on('issues')->cascadeOnDelete();
            $table->foreignId('author_id');
            $table->foreign('author_id')->references('id')->on('users')->cascadeOnDelete();
            $table->date('worked_at');
            $table->decimal('hours', 6, 2);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('issue_id');
            $table->index(['workspace_id', 'author_id']);
        });

        // CHECK constraints are enforced at the DB level on PostgreSQL.
        // SQLite (used in tests) does not support ALTER TABLE ADD CONSTRAINT,
        // and the application-level validation already covers it.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE time_entries ADD CONSTRAINT time_entries_hours_check CHECK (hours > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries');
    }
};

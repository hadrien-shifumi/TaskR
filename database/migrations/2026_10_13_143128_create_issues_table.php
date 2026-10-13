<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issues', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->ulid('project_id');
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->ulid('parent_id')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->ulid('status_id');
            $table->foreign('status_id')->references('id')->on('statuses')->restrictOnDelete();
            $table->string('priority')->default('none');
            $table->date('due_date')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status_id']);
            $table->index(['workspace_id', 'project_id']);
            $table->index('parent_id');
        });

        // Self-referencing FK added after create: Postgres requires the
        // primary key to exist before it can be referenced.
        Schema::table('issues', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('issues')->nullOnDelete();
        });

        // CHECK constraints are enforced at the DB level on PostgreSQL.
        // SQLite (used in tests) does not support ALTER TABLE ADD CONSTRAINT,
        // and the application-level validation already covers it.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE issues ADD CONSTRAINT issues_priority_check CHECK (priority IN ('none', 'low', 'high', 'urgent'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('issues');
    }
};

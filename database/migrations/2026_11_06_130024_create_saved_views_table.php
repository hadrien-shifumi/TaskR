<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_views', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->string('name');
            $table->string('summary')->nullable();
            $table->json('filters')->nullable();
            $table->string('sort')->nullable();
            $table->string('dir')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
        });

        // CHECK constraints are enforced at the DB level on PostgreSQL.
        // SQLite (used in tests) does not support ALTER TABLE ADD CONSTRAINT,
        // and the application-level sanitization already covers it.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE saved_views ADD CONSTRAINT saved_views_sort_check CHECK (sort IS NULL OR sort IN ('created', 'priority', 'due_date', 'status', 'project'))");
            DB::statement("ALTER TABLE saved_views ADD CONSTRAINT saved_views_dir_check CHECK (dir IS NULL OR dir IN ('asc', 'desc'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_views');
    }
};

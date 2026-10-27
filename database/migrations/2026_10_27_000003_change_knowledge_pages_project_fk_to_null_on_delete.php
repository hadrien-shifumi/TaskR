<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite (tests) cannot drop individual FK constraints; the cascade
        // is neutralized in DeleteProject by nulling project_id before the
        // project row is removed. Only PostgreSQL needs the FK change.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Drop the existing FK (cascadeOnDelete).
        Schema::table('knowledge_pages', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
        });

        // Re-add with nullOnDelete instead of cascadeOnDelete.
        // When a project is deleted, knowledge pages will have project_id
        // set to null (they become transverse). The actual soft delete is
        // handled by DeleteProject.
        Schema::table('knowledge_pages', function (Blueprint $table) {
            $table->foreign('project_id')
                ->references('id')
                ->on('projects')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Restore cascadeOnDelete.
        Schema::table('knowledge_pages', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
        });

        Schema::table('knowledge_pages', function (Blueprint $table) {
            $table->foreign('project_id')
                ->references('id')
                ->on('projects')
                ->cascadeOnDelete();
        });
    }
};

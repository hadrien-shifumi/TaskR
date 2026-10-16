<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_pages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->ulid('project_id')->nullable();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->ulid('parent_id')->nullable();
            $table->string('title');
            $table->text('body');
            // users.id is a bigint (auto-increment), not a ULID — every other
            // table references it with foreignId().
            $table->foreignId('last_editor_id');
            $table->foreign('last_editor_id')->references('id')->on('users')->cascadeOnDelete();
            $table->timestamp('embedded_at')->nullable();
            $table->timestamps();

            $table->index('workspace_id');
            $table->index('project_id');
            $table->index('parent_id');
        });

        // Self-referencing FK added after create: Postgres requires the
        // primary key to exist before it can be referenced.
        Schema::table('knowledge_pages', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('knowledge_pages')->nullOnDelete();
        });

        // Embedding storage depends on the driver: pgvector in production
        // (dimension fixed at 1024 — mistral-embed by default; changing the
        // model or dimensions requires a new migration plus
        // `php artisan knowledge:reembed`), JSON text on sqlite for dev/tests.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE knowledge_pages ADD COLUMN embedding vector(1024)');
            DB::statement('CREATE INDEX knowledge_pages_embedding_ivfflat ON knowledge_pages USING ivfflat (embedding vector_cosine_ops)');
        } else {
            Schema::table('knowledge_pages', function (Blueprint $table) {
                $table->text('embedding')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_pages');
    }
};

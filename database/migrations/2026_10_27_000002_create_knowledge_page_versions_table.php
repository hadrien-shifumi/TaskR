<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_page_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('page_id');
            $table->foreign('page_id')->references('id')->on('knowledge_pages')->cascadeOnDelete();
            $table->string('title');
            $table->text('body');
            // users.id is a bigint (auto-increment), not a ULID — every other
            // table references it with foreignId().
            $table->foreignId('editor_id');
            $table->foreign('editor_id')->references('id')->on('users')->cascadeOnDelete();
            // A version is a point-in-time snapshot: created once, never
            // updated — hence no updated_at column.
            $table->timestamp('created_at')->useCurrent();

            $table->index('page_id');
            $table->index(['page_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_page_versions');
    }
};

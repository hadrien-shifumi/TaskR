<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One conversation between a user and TaskR's assistant. The thread
        // belongs to its workspace (scope) and to its author alone: another
        // member of the same workspace never sees it, and the Mercure
        // channel of the thread authorizes the author only. `locale` is the
        // language the conversation's answers are written in, snapshot from
        // the application locale of the turn — the queued worker restores
        // it since no request middleware runs there.
        Schema::create('chat_threads', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            // users.id is a bigint (auto-increment), not a ULID — every other
            // table references it with foreignId().
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->string('locale', 8)->nullable();
            $table->timestamps();

            // The threads list of a user inside their workspace, most
            // recent first.
            $table->index(['workspace_id', 'user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_threads');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One turn of the conversation, user or assistant. The streaming
        // fragments live only in Mercure; this table stores the settled
        // state the thread reloads from: the final texts, the assistant's
        // tool rounds (compact, displayable journal), the token usage the
        // provider reported, and the outcome — a pending assistant message
        // while its job runs, complete or failed once it ends.
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('thread_id')->constrained('chat_threads')->cascadeOnDelete();
            $table->string('role', 16); // user | assistant
            $table->text('body')->nullable();
            // Assistant only: the turn's tool rounds as a compact journal
            // [{callId, name, arguments, ok}] — the raw provider payloads
            // never leave the request context.
            $table->json('tool_calls')->nullable();
            $table->json('usage')->nullable();
            // Assistant only: pending until its completion job ends, then
            // complete or failed with a displayable error line.
            $table->string('status', 16)->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            // The thread's transcript reads in order.
            $table->index(['thread_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};

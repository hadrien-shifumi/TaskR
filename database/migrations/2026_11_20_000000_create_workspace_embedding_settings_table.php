<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per workspace: the owner-configured embeddings provider
        // for the knowledge base (semantic search + vectorization). The API
        // key is stored encrypted (model cast) and never leaves the server.
        // `dimensions` holds the dimension the save-time probe observed —
        // it must match the instance's pgvector column — and
        // `send_dimensions` records the explicit opt-in asking the provider
        // to return that dimension (e.g. OpenAI's downscaled vectors).
        Schema::create('workspace_embedding_settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('base_url');
            $table->text('api_key');
            $table->string('model');
            $table->unsignedInteger('dimensions');
            $table->boolean('send_dimensions')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_embedding_settings');
    }
};

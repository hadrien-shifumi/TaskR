<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The chat block joins the same row as the embeddings provider, and
        // the owner may configure the chat before the embeddings pair: the
        // embeddings columns become nullable. A row saved only through the
        // embeddings settings always passed the save-time probe (the four
        // values observed and stored together), so the contract surfaces —
        // the encrypted cast, the resolution chain, the audit journal —
        // stay untouched; the resolution reports the unconfigured half.
        Schema::table('workspace_embedding_settings', function (Blueprint $table) {
            $table->string('base_url')->nullable()->change();
            $table->text('api_key')->nullable()->change();
            $table->string('model')->nullable()->change();
            $table->unsignedInteger('dimensions')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('workspace_embedding_settings', function (Blueprint $table) {
            $table->string('base_url')->nullable(false)->change();
            $table->text('api_key')->nullable(false)->change();
            $table->string('model')->nullable(false)->change();
            $table->unsignedInteger('dimensions')->nullable(false)->change();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();

            // Subject: exactly one of the three FKs is set. The journal
            // outlives its subject (nullOnDelete), so a deletion is still
            // readable after the fact.
            $table->ulid('issue_id')->nullable();
            $table->foreign('issue_id')->references('id')->on('issues')->nullOnDelete();
            $table->ulid('project_id')->nullable();
            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
            $table->ulid('knowledge_page_id')->nullable();
            $table->foreign('knowledge_page_id')->references('id')->on('knowledge_pages')->nullOnDelete();

            // users.id is a bigint (auto-increment), not a ULID. Null means a
            // system action; the journal survives the actor's deletion.
            $table->foreignId('actor_id')->nullable();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();

            $table->string('type', 64);
            $table->json('changes')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'issue_id']);
            $table->index(['workspace_id', 'project_id']);
            $table->index(['workspace_id', 'knowledge_page_id']);
            $table->index(['workspace_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};

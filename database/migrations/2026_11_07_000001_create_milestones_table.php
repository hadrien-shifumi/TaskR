<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milestones', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->ulid('project_id');
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->ulid('parent_id')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('start_date')->nullable();
            $table->date('target_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'project_id']);
            $table->index('parent_id');
        });

        // Self-referencing FK added after create: Postgres requires the
        // primary key to exist before it can be referenced.
        Schema::table('milestones', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('milestones')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milestones');
    }
};

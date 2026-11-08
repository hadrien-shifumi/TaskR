<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('active_timers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreignId('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->ulid('issue_id');
            $table->foreign('issue_id')->references('id')->on('issues')->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamps();

            $table->unique(['user_id', 'workspace_id'], 'active_timers_user_workspace_unique');
            $table->index('issue_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('active_timers');
    }
};

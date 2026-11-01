<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Personal, per-account settings: like personal_access_tokens, this
        // table is NOT workspace-scoped — one row per user, at most.
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('frequency', 10)->default('daily');
            $table->unsignedSmallInteger('upcoming_threshold_days')->default(7);
            $table->string('timezone', 64)->default('Europe/Paris');
            $table->timestamp('last_digest_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};

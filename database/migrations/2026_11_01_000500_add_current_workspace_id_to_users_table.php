<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The workspace the account last activated in the web UI. The
        // session owns the per-browser state; this column is the
        // account-wide mirror the background jobs read (the digest has no
        // session). Nullable: an account that never navigated the UI
        // falls back to its first workspace by name. nullOnDelete: the
        // account survives the workspace, it just loses its active one.
        Schema::table('users', function (Blueprint $table) {
            $table->ulid('current_workspace_id')->nullable()->after('locale');
            $table->foreign('current_workspace_id')->references('id')->on('workspaces')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['current_workspace_id']);
            $table->dropColumn('current_workspace_id');
        });
    }
};

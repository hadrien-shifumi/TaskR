<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->ulid('milestone_id')->nullable()->after('knowledge_page_id');
            $table->foreign('milestone_id')->references('id')->on('milestones')->nullOnDelete();
            $table->index(['workspace_id', 'milestone_id']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'milestone_id']);
            $table->dropConstrainedForeignId('milestone_id');
        });
    }
};

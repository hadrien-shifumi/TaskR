<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            // An issue carries at most one milestone (0-1): the single
            // column is the invariant. Deleting the milestone detaches
            // the issue instead of deleting it.
            $table->ulid('milestone_id')->nullable()->after('parent_id');
            $table->foreign('milestone_id')->references('id')->on('milestones')->nullOnDelete();
            $table->index('milestone_id');
        });
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->dropConstrainedForeignId('milestone_id');
        });
    }
};

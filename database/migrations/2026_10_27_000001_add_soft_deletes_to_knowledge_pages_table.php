<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_pages', function (Blueprint $table) {
            $table->softDeletes();
            // users.id is a bigint (auto-increment), not a ULID — every other
            // table references it with foreignId().
            $table->foreignId('deleted_by_id')->nullable()->after('deleted_at');
            $table->foreign('deleted_by_id')->references('id')->on('users')->nullOnDelete();
            $table->string('deleted_reason')->nullable()->after('deleted_by_id');

            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_pages', function (Blueprint $table) {
            $table->dropIndex(['deleted_at']);
            $table->dropConstrainedForeignId('deleted_by_id');
            $table->dropColumn(['deleted_at', 'deleted_reason']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A comment is not an audit subject: its events target the parent issue.
     * The comment id is a metadata of the event, not a before/after diff, so
     * it gets its own column instead of polluting `changes`.
     *
     * No foreign key on purpose: a `comment.deleted` event is written right
     * before the destructive delete and must keep pointing at the comment it
     * is about. A FK would null the id on delete, erasing the only link the
     * timeline has to the removed comment.
     */
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->ulid('comment_id')->nullable()->after('knowledge_page_id');
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->dropColumn('comment_id');
        });
    }
};

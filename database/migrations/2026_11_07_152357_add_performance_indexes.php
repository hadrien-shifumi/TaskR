<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performance indexes for the hot paths. PostgreSQL does not index foreign
 * keys implicitly: every column the hot queries filter or sort on needs an
 * explicit index. Grouped here because they are pure read-optimizations —
 * no column or constraint changes.
 *
 * - issues (workspace_id, archived_at, created_at): default issue list —
 *   unarchived rows of a workspace, newest first (plus the list COUNTs).
 * - issues (project_id, archived_at, created_at): project board and the
 *   archived-per-project listings.
 * - issues (status_id): status joins of every health/board aggregate; bare
 *   FK with no implicit index.
 * - issues (due_date): overdue filter and due-date sort.
 * - issue_assignees (user_id) / issue_labels (label_id): the composite
 *   primaries lead with the issue side; the reverse lookups (assignee
 *   filter, label filter) need their own index.
 * - project_members (user_id): a guest's scope resolution, run many times
 *   per request (memoized in WorkspaceContext, but still per-request).
 * - memberships (workspace_id): member listings and filters; the unique
 *   index leads with user_id.
 * - audit_events (project_id, type, created_at): project activity page and
 *   trends filter by project + type, ordered by created_at.
 * - time_entries (workspace_id, worked_at): dashboard weekly hours range.
 * - comments (issue_id, created_at): comment thread of an issue, ordered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->index(['workspace_id', 'archived_at', 'created_at'], 'issues_workspace_archived_created_idx');
            $table->index(['project_id', 'archived_at', 'created_at'], 'issues_project_archived_created_idx');
            $table->index('status_id', 'issues_status_id_idx');
            $table->index('due_date', 'issues_due_date_idx');
        });

        Schema::table('issue_assignees', function (Blueprint $table) {
            $table->index('user_id', 'issue_assignees_user_id_idx');
        });

        Schema::table('issue_labels', function (Blueprint $table) {
            $table->index('label_id', 'issue_labels_label_id_idx');
        });

        Schema::table('project_members', function (Blueprint $table) {
            $table->index('user_id', 'project_members_user_id_idx');
        });

        Schema::table('memberships', function (Blueprint $table) {
            $table->index('workspace_id', 'memberships_workspace_id_idx');
        });

        Schema::table('audit_events', function (Blueprint $table) {
            $table->index(['project_id', 'type', 'created_at'], 'audit_events_project_type_created_idx');
        });

        Schema::table('time_entries', function (Blueprint $table) {
            $table->index(['workspace_id', 'worked_at'], 'time_entries_workspace_worked_at_idx');
        });

        Schema::table('comments', function (Blueprint $table) {
            $table->index(['issue_id', 'created_at'], 'comments_issue_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->dropIndex('issues_workspace_archived_created_idx');
            $table->dropIndex('issues_project_archived_created_idx');
            $table->dropIndex('issues_status_id_idx');
            $table->dropIndex('issues_due_date_idx');
        });

        Schema::table('issue_assignees', function (Blueprint $table) {
            $table->dropIndex('issue_assignees_user_id_idx');
        });

        Schema::table('issue_labels', function (Blueprint $table) {
            $table->dropIndex('issue_labels_label_id_idx');
        });

        Schema::table('project_members', function (Blueprint $table) {
            $table->dropIndex('project_members_user_id_idx');
        });

        Schema::table('memberships', function (Blueprint $table) {
            $table->dropIndex('memberships_workspace_id_idx');
        });

        Schema::table('audit_events', function (Blueprint $table) {
            $table->dropIndex('audit_events_project_type_created_idx');
        });

        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropIndex('time_entries_workspace_worked_at_idx');
        });

        Schema::table('comments', function (Blueprint $table) {
            $table->dropIndex('comments_issue_created_idx');
        });
    }
};

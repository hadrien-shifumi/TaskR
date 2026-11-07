<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PostgreSQL does not create indexes for foreign keys: every column the
 * hot paths filter or sort on must be indexed explicitly. These tests pin
 * the performance indexes the hot queries rely on (issue list ordering,
 * guest scoping, activity journal, time exports).
 */
class PerformanceIndexesTest extends TestCase
{
    use RefreshDatabase;

    private function assertTableHasIndex(string $table, array $columns): void
    {
        $found = collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => $index['columns'] === $columns);

        $this->assertTrue(
            $found,
            sprintf('Missing index on %s (%s).', $table, implode(', ', $columns)),
        );
    }

    public function test_issues_table_carries_the_hot_path_indexes(): void
    {
        // Default issue list ordering + unarchived filters (the most run
        // query of the app) and the project board equivalent.
        $this->assertTableHasIndex('issues', ['workspace_id', 'archived_at', 'created_at']);
        $this->assertTableHasIndex('issues', ['project_id', 'archived_at', 'created_at']);

        // Status joins (health aggregates, status sort) and the overdue
        // filter: bare FK columns with no implicit index under PostgreSQL.
        $this->assertTableHasIndex('issues', ['status_id']);
        $this->assertTableHasIndex('issues', ['due_date']);
    }

    public function test_pivot_tables_carry_reverse_lookup_indexes(): void
    {
        // Composite primaries lead with the issue/project side; the
        // reverse lookups (by user or label) need their own index.
        $this->assertTableHasIndex('issue_assignees', ['user_id']);
        $this->assertTableHasIndex('issue_labels', ['label_id']);
        $this->assertTableHasIndex('project_members', ['user_id']);

        // Membership listings by workspace (member filters, Inertia
        // shares) — the unique index leads with user_id instead.
        $this->assertTableHasIndex('memberships', ['workspace_id']);
    }

    public function test_activity_and_time_tables_carry_filter_indexes(): void
    {
        // Project activity page and trends filter by project + type and
        // sort by created_at; the existing index leads with workspace_id.
        $this->assertTableHasIndex('audit_events', ['project_id', 'type', 'created_at']);

        // Dashboard weekly hours (workspace + worked_at range) and the
        // comment thread ordering (issue + created_at).
        $this->assertTableHasIndex('time_entries', ['workspace_id', 'worked_at']);
        $this->assertTableHasIndex('comments', ['issue_id', 'created_at']);
    }
}

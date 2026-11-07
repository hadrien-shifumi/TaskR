<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\Statuses\CreateStatus;
use App\Actions\Statuses\ReorderStatuses;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Statuses are read on every issues surface (list, kanban, fiche, board)
 * and written through exactly four owner paths (create, update, delete,
 * reorder). They ride a per-workspace cache: repeated page loads must not
 * re-query them, and every write path must invalidate the cache — the
 * bulk reorder included, which bypasses model events.
 */
class WorkspaceStatusesCacheTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace];
    }

    /**
     * Count the workspace-level status list reads (the cached query —
     * recognized by its position ordering), not the per-issue eager loads
     * and sub-queries that also touch the table.
     */
    private function statusReadCount(): int
    {
        return collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql): bool => str_starts_with($sql, 'select * from "statuses"')
                && str_contains($sql, 'order by "position"'))
            ->count();
    }

    public function test_statuses_are_served_from_the_cache_between_requests(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'A']);

        // Prime the cache.
        $this->actingAs($owner)->get('/issues')->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($owner)
            ->get('/issues')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('statuses', 4));

        $this->assertSame(0, $this->statusReadCount());
    }

    public function test_statuses_cache_payload_survives_restricted_unserialization(): void
    {
        [, $workspace] = $this->setupWorkspace();

        // Prime the cache.
        $workspace->cachedStatuses();

        $payload = Cache::get(Workspace::statusesCacheKey($workspace->id));

        // Laravel 13 hardened its cache stores: by default they unserialize
        // with allowed_classes = false (cache.serializable_classes), so any
        // PHP object in the payload degrades to __PHP_Incomplete_Class and
        // the first ->map() crashes. The payload must be plain rows that
        // survive this round-trip untouched.
        $roundTripped = unserialize(serialize($payload), ['allowed_classes' => false]);

        $this->assertIsArray($roundTripped);
        $this->assertNotEmpty($roundTripped);
        foreach ($roundTripped as $row) {
            $this->assertIsArray($row);
            $this->assertIsString($row['id']);
            $this->assertIsString($row['name']);
            $this->assertIsString($row['category']);
        }

        // Same statuses, same position order as the relation query.
        $this->assertSame(
            $workspace->statuses()->pluck('id')->all(),
            array_column($roundTripped, 'id'),
        );
    }

    public function test_cached_statuses_are_returned_as_hydrated_models(): void
    {
        [, $workspace] = $this->setupWorkspace();

        // Prime the cache, then read it back.
        $workspace->cachedStatuses();
        $fromCache = $workspace->cachedStatuses();

        $this->assertContainsOnlyInstancesOf(Status::class, $fromCache);

        $expected = $workspace->statuses()->get();
        $this->assertSame(
            $expected->map(fn (Status $status) => [$status->id, $status->name, $status->category->value])->all(),
            $fromCache->map(fn (Status $status) => [$status->id, $status->name, $status->category->value])->all(),
        );
    }

    public function test_statuses_cache_is_refreshed_on_status_writes(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        // Prime the cache.
        $this->actingAs($owner)->get('/issues')->assertOk();

        CreateStatus::run($workspace, ['name' => 'Review', 'category' => 'in_progress']);

        $this->actingAs($owner)
            ->get('/issues')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('statuses', 5));
    }

    public function test_statuses_cache_is_refreshed_after_a_reorder(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        // Prime the cache and capture the original order from the page
        // payload embedded in the response.
        $response = $this->actingAs($owner)->get('/issues')->assertOk();
        $page = $response->original->getData()['page'] ?? null;
        $this->assertIsArray($page);
        $originalOrder = $page['props']['statuses'] ?? null;
        $this->assertNotNull($originalOrder);

        $reversed = array_reverse(array_map(fn ($status) => $status['id'], $originalOrder));
        ReorderStatuses::run($workspace, ['ids' => $reversed]);

        $this->actingAs($owner)
            ->get('/issues')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('statuses.0.id', $reversed[0])
                ->where('statuses.1.id', $reversed[1]));
    }
}

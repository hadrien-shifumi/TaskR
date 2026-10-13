<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Membership;
use App\Models\Status;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class StatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_status(): void
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);

        $this->actingAs($owner)
            ->post('/statuses', ['name' => 'In Review', 'category' => 'in_progress'])
            ->assertRedirect(route('settings.statuses'));

        $this->assertDatabaseHas('statuses', [
            'workspace_id' => $workspace->id,
            'name' => 'In Review',
            'category' => 'in_progress',
            'position' => 4,
        ]);
    }

    public function test_member_cannot_manage_statuses(): void
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);

        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        app(WorkspaceContext::class)->set($workspace);
        $status = $workspace->statuses()->first();

        $this->actingAs($member)->post('/statuses', ['name' => 'X', 'category' => 'backlog'])->assertForbidden();
        $this->actingAs($member)->patch("/statuses/{$status->id}", ['name' => 'X', 'category' => 'backlog'])->assertForbidden();
        $this->actingAs($member)->delete("/statuses/{$status->id}")->assertForbidden();
        $this->actingAs($member)->post('/statuses/reorder', ['ids' => [$status->id]])->assertForbidden();
    }

    public function test_status_names_are_unique_per_workspace(): void
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);

        $this->actingAs($owner)
            ->post('/statuses', ['name' => 'Backlog', 'category' => 'backlog'])
            ->assertSessionHasErrors('name');
    }

    public function test_owner_can_update_status(): void
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);

        app(WorkspaceContext::class)->set($workspace);
        $status = $workspace->statuses()->first();

        $this->actingAs($owner)
            ->patch("/statuses/{$status->id}", ['name' => 'Renamed', 'category' => 'done'])
            ->assertRedirect(route('settings.statuses'));

        $this->assertDatabaseHas('statuses', [
            'id' => $status->id,
            'name' => 'Renamed',
            'category' => 'done',
        ]);
    }

    public function test_owner_can_reorder_statuses(): void
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);

        app(WorkspaceContext::class)->set($workspace);
        $ids = $workspace->statuses()->orderBy('position')->pluck('id')->reverse()->values()->all();

        $this->actingAs($owner)
            ->post('/statuses/reorder', ['ids' => $ids])
            ->assertRedirect(route('settings.statuses'));

        $this->assertSame($ids, $workspace->statuses()->orderBy('position')->pluck('id')->all());
    }

    public function test_statuses_page_exposes_fixed_categories(): void
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);

        $this->actingAs($owner)
            ->get('/settings/statuses')
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Settings/Statuses')
                    ->has('statuses', 4)
                    ->where('statuses.0.category', 'backlog')
            );
    }

    public function test_owner_can_delete_unused_status(): void
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);

        $status = Status::create([
            'workspace_id' => $workspace->id,
            'name' => 'Extra',
            'category' => 'backlog',
            'position' => 4,
        ]);

        $this->actingAs($owner)
            ->delete("/statuses/{$status->id}")
            ->assertRedirect(route('settings.statuses'));

        $this->assertDatabaseMissing('statuses', ['id' => $status->id]);
    }
}

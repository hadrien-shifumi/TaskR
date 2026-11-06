<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class WorkspaceGeneralSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_general_settings_page_renders_with_current_toggle_value(): void
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);

        $this->actingAs($owner)
            ->get(route('settings.general'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/General')
                ->where('parentsAsContainers', false));
    }

    public function test_owner_can_toggle_the_setting(): void
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);

        $this->actingAs($owner)
            ->put(route('settings.general.update'), ['parents_as_containers' => '1'])
            ->assertRedirect(route('settings.general'));

        $this->assertDatabaseHas('workspaces', [
            'id' => $workspace->id,
            'parents_as_containers' => true,
        ]);

        $this->actingAs($owner)
            ->put(route('settings.general.update'), ['parents_as_containers' => '0'])
            ->assertRedirect(route('settings.general'));

        $this->assertDatabaseHas('workspaces', [
            'id' => $workspace->id,
            'parents_as_containers' => false,
        ]);
    }

    public function test_member_cannot_update_the_setting(): void
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);

        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        $this->actingAs($member)
            ->put(route('settings.general.update'), ['parents_as_containers' => '1'])
            ->assertForbidden();

        $this->assertDatabaseHas('workspaces', [
            'id' => $workspace->id,
            'parents_as_containers' => false,
        ]);
    }

    public function test_guest_cannot_update_the_setting(): void
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);

        $guest = User::factory()->create();
        Membership::create([
            'user_id' => $guest->id,
            'workspace_id' => $workspace->id,
            'role' => 'guest',
        ]);

        app(WorkspaceContext::class)->set($workspace);

        $this->actingAs($guest)
            ->put(route('settings.general.update'), ['parents_as_containers' => '1'])
            ->assertForbidden();

        $this->assertDatabaseHas('workspaces', [
            'id' => $workspace->id,
            'parents_as_containers' => false,
        ]);
    }

    public function test_update_rejects_a_missing_value(): void
    {
        $owner = User::factory()->create();
        CreateWorkspace::run($owner, ['name' => 'WS']);

        $this->actingAs($owner)
            ->put(route('settings.general.update'), [])
            ->assertSessionHasErrors('parents_as_containers');
    }

    public function test_new_workspaces_start_with_the_setting_disabled(): void
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'Fresh']);

        $this->assertInstanceOf(Workspace::class, $workspace);
        $this->assertFalse($workspace->fresh()->parents_as_containers);
    }
}

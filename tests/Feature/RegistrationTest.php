<?php

namespace Tests\Feature;

use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_user_workspace_and_default_statuses(): void
    {
        $response = $this->post('/register', [
            'name' => 'Alice',
            'email' => 'alice@test.dev',
            'password' => 'sup3r-secret-password',
            'password_confirmation' => 'sup3r-secret-password',
            'workspace_name' => 'Studio Alice',
        ]);

        $response->assertRedirect(route('home', absolute: false));

        $user = User::where('email', 'alice@test.dev')->firstOrFail();
        $this->assertAuthenticatedAs($user);

        $workspace = Workspace::where('name', 'Studio Alice')->firstOrFail();
        $this->assertTrue($workspace->hasOwner($user));

        $statuses = Status::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->orderBy('position')->get();
        $this->assertSame(
            ['Backlog', 'In Progress', 'Done', 'Cancelled'],
            $statuses->pluck('name')->all(),
        );
        $this->assertSame(
            ['backlog', 'in_progress', 'done', 'cancelled'],
            $statuses->pluck('category')->map(fn ($c) => $c->value)->all(),
        );
    }

    public function test_registration_requires_unique_email(): void
    {
        User::factory()->create(['email' => 'bob@test.dev']);

        $this->from(route('register', absolute: false))
            ->post('/register', [
                'name' => 'Bob',
                'email' => 'bob@test.dev',
                'password' => 'sup3r-secret-password',
                'password_confirmation' => 'sup3r-secret-password',
            ])
            ->assertSessionHasErrors('email');
    }
}

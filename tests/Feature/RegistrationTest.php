<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Invitation;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

    public function test_registration_honors_the_invitation_redirect_parameter(): void
    {
        $response = $this->post('/register', [
            'name' => 'Alice',
            'email' => 'alice@test.dev',
            'password' => 'sup3r-secret-password',
            'password_confirmation' => 'sup3r-secret-password',
            'redirect' => '/invitations/some-token/accept',
        ]);

        $response->assertRedirect('/invitations/some-token/accept');
    }

    public function test_registration_ignores_an_external_redirect_parameter(): void
    {
        $response = $this->post('/register', [
            'name' => 'Alice',
            'email' => 'alice@test.dev',
            'password' => 'sup3r-secret-password',
            'password_confirmation' => 'sup3r-secret-password',
            'redirect' => 'https://evil.example.com',
        ]);

        $response->assertRedirect(route('home', absolute: false));
    }

    public function test_registration_from_an_invitation_link_lands_the_new_user_in_the_invited_workspace(): void
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'Studio Nova']);
        $invitation = Invitation::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'email' => 'newcomer@test.dev',
            'token' => Str::random(64),
            'expires_at' => now()->addDays(7),
        ]);

        // The invitation landing page forwards its return target through the
        // login form, whose signup tab must hand it over to registration.
        $this->post('/register', [
            'name' => 'Newcomer',
            'email' => 'newcomer@test.dev',
            'password' => 'sup3r-secret-password',
            'password_confirmation' => 'sup3r-secret-password',
            'redirect' => "/invitations/{$invitation->token}/accept",
        ])->assertRedirect("/invitations/{$invitation->token}/accept");

        // The acceptance replays for the freshly authenticated user: they
        // join the invited workspace, which becomes their current one.
        $this->assertAuthenticated();

        $this->get("/invitations/{$invitation->token}/accept")
            ->assertRedirect(route('home', absolute: false))
            ->assertSessionHas('status');

        $user = User::where('email', 'newcomer@test.dev')->firstOrFail();
        $this->assertTrue($user->memberships()->where('workspace_id', $workspace->id)->exists());
        $this->assertSame($workspace->id, $user->current_workspace_id);
        $this->assertNotNull($invitation->fresh()->accepted_at);
    }
}

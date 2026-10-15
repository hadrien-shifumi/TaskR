<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Invitation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AcceptInvitationGuestTest extends TestCase
{
    use RefreshDatabase;

    private function invitation(array $attributes = []): Invitation
    {
        [$owner, $workspace] = $this->workspaceWithOwner();

        return Invitation::withoutWorkspaceScope()->create(array_merge([
            'workspace_id' => $workspace->id,
            'email' => 'guest@example.com',
            'token' => Str::random(64),
            'expires_at' => now()->addDays(7),
        ], $attributes));
    }

    /**
     * @return array{User, Workspace}
     */
    private function workspaceWithOwner(): array
    {
        $owner = User::factory()->create(['name' => 'Camille Riva']);
        $workspace = CreateWorkspace::run($owner, ['name' => 'Studio Nova']);

        return [$owner, $workspace];
    }

    public function test_guest_sees_the_invitation_page_for_a_usable_invitation(): void
    {
        $invitation = $this->invitation();

        $this->get("/invitations/{$invitation->token}/accept")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Auth/AcceptInvitation')
                ->where('invitation.workspaceName', 'Studio Nova')
                ->where('invitation.inviterName', 'Camille Riva')
                ->where('invitation.email', 'guest@example.com')
                ->where('invitation.role', 'member')
                ->where('invitation.acceptUrl', "/invitations/{$invitation->token}/accept"));
    }

    public function test_guest_gets_gone_for_an_expired_invitation(): void
    {
        $invitation = $this->invitation(['expires_at' => now()->subDay()]);

        $this->get("/invitations/{$invitation->token}/accept")->assertStatus(410);
    }

    public function test_guest_gets_gone_for_an_already_accepted_invitation(): void
    {
        $invitation = $this->invitation(['accepted_at' => now()]);

        $this->get("/invitations/{$invitation->token}/accept")->assertStatus(410);
    }

    public function test_guest_gets_not_found_for_an_unknown_token(): void
    {
        $this->get('/invitations/'.Str::random(64).'/accept')->assertNotFound();
    }

    public function test_login_redirects_to_the_internal_redirect_parameter(): void
    {
        $invitation = $this->invitation();
        User::factory()->create([
            'email' => 'guest@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $this->post('/login', [
            'email' => 'guest@example.com',
            'password' => 'secret123',
            'redirect' => "/invitations/{$invitation->token}/accept",
        ])->assertRedirect("/invitations/{$invitation->token}/accept");
    }

    public function test_login_ignores_an_external_redirect_parameter(): void
    {
        User::factory()->create([
            'email' => 'guest@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $this->post('/login', [
            'email' => 'guest@example.com',
            'password' => 'secret123',
            'redirect' => 'https://evil.example.com',
        ])->assertRedirect('/');
    }
}

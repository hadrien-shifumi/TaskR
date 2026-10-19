<?php

namespace Tests\Feature;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceAuthorizationHelperTest extends TestCase
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

    private function addUser(Workspace $workspace, string $role): User
    {
        $user = User::factory()->create();
        Membership::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'role' => $role,
        ]);

        return $user;
    }

    /**
     * Hosts the trait exactly like an action would, with public proxies
     * so the protected helpers can be asserted from the test scope.
     */
    private function helper(): object
    {
        return new class
        {
            use AuthorizesWorkspaceAccess;

            public function canUseWorkspace(): bool
            {
                return $this->currentUserCanUseWorkspace();
            }

            public function isFullMember(): bool
            {
                return $this->currentUserIsFullMember();
            }

            public function workspace(): ?Workspace
            {
                return $this->currentWorkspace();
            }
        };
    }

    /**
     * Mirrors what the Authenticate middleware does to the request
     * during a real call: without the resolver, request()->user()
     * legitimately resolves to null.
     */
    private function actingRequestUser(?User $user): void
    {
        request()->setUserResolver(fn () => $user);
    }

    public function test_current_user_can_use_workspace_accepts_every_membership_role(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $users = [
            'owner' => $owner,
            'member' => $this->addUser($workspace, 'member'),
            'guest' => $this->addUser($workspace, 'guest'),
        ];

        foreach ($users as $role => $user) {
            $this->actingRequestUser($user);

            $this->assertTrue($this->helper()->canUseWorkspace(), "{$role} should pass the belonging check");
        }
    }

    public function test_current_user_can_use_workspace_rejects_a_non_member(): void
    {
        $this->setupWorkspace();
        $outsider = User::factory()->create();
        $this->actingRequestUser($outsider);

        $this->assertFalse($this->helper()->canUseWorkspace());
    }

    public function test_current_user_can_use_workspace_is_false_without_a_resolved_workspace_context(): void
    {
        [$owner] = $this->setupWorkspace();
        app(WorkspaceContext::class)->set(null);
        $this->actingRequestUser($owner);

        $this->assertFalse($this->helper()->canUseWorkspace());
    }

    public function test_current_user_is_full_member_excludes_guests_and_outsiders(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $users = [
            'owner' => $owner,
            'member' => $this->addUser($workspace, 'member'),
        ];

        foreach ($users as $role => $user) {
            $this->actingRequestUser($user);

            $this->assertTrue($this->helper()->isFullMember(), "{$role} should be a full member");
        }

        $guest = $this->addUser($workspace, 'guest');
        $this->actingRequestUser($guest);
        $this->assertFalse($this->helper()->isFullMember(), 'guest should be excluded from workspace-wide surfaces');

        $outsider = User::factory()->create();
        $this->actingRequestUser($outsider);
        $this->assertFalse($this->helper()->isFullMember());
    }

    public function test_current_workspace_resolves_the_context_workspace(): void
    {
        [, $workspace] = $this->setupWorkspace();

        $this->assertSame($workspace->id, $this->helper()->workspace()?->id);
    }
}

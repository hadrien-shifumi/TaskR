<?php

namespace App\Actions\Workspaces;

use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Lorisleiva\Actions\Concerns\AsObject;

class ResolveAgentWorkspace
{
    use AsObject;

    /**
     * Verifies that the user belongs to the requested workspace and makes it
     * the current workspace context for this call. Non-existent and foreign
     * workspaces fail identically: no existence leak.
     *
     * Re-resolved on every agent tool call, never cached between calls; the
     * MCP tool envelope flushes the context when the call ends.
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, string $workspaceId): Workspace
    {
        $membership = $user->memberships()
            ->where('workspace_id', $workspaceId)
            ->first();

        if ($membership === null) {
            throw new AuthorizationException('You are not a member of this workspace.');
        }

        /** @var Workspace $workspace */
        $workspace = $membership->workspace()->firstOrFail();

        app(WorkspaceContext::class)->set($workspace);

        return $workspace;
    }
}

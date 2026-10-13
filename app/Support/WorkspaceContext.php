<?php

namespace App\Support;

use App\Models\User;
use App\Models\Workspace;

/**
 * Holds the workspace resolved for the current request (or null).
 *
 * Bound as a singleton; set by the ResolveCurrentWorkspace middleware.
 * All workspace-scoped models filter themselves against this context
 * via the BelongsToWorkspace trait global scope.
 */
class WorkspaceContext
{
    protected ?Workspace $workspace = null;

    public function set(?Workspace $workspace): void
    {
        $this->workspace = $workspace;
    }

    public function get(): ?Workspace
    {
        return $this->workspace;
    }

    public function id(): ?string
    {
        return $this->workspace?->id;
    }

    public function getOrFail(): Workspace
    {
        return $this->workspace ?? abort(404, 'No active workspace.');
    }

    public function isMember(User $user): bool
    {
        if ($this->workspace === null) {
            return false;
        }

        return $this->workspace->hasMember($user);
    }
}

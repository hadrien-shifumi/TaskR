<?php

namespace App\Actions\Concerns;

use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;

/**
 * Shared workspace authorization helpers for actions.
 *
 * Extracts the membership checks every workspace-scoped authorize()
 * needs. currentUserCanUseWorkspace accepts any membership — guests
 * included, belonging is permission; currentUserIsFullMember excludes
 * guests from workspace-wide surfaces (members page, labels, tokens).
 * Both read the current request user and the workspace resolved by
 * the ResolveCurrentWorkspace middleware, exactly like the inline
 * checks they replace.
 */
trait AuthorizesWorkspaceAccess
{
    protected function currentUserCanUseWorkspace(): bool
    {
        $user = request()->user();

        return $user instanceof User && app(WorkspaceContext::class)->canUseWorkspace($user);
    }

    protected function currentUserIsFullMember(): bool
    {
        $user = request()->user();

        return $user instanceof User && app(WorkspaceContext::class)->isFullMember($user);
    }

    protected function currentWorkspace(): ?Workspace
    {
        return app(WorkspaceContext::class)->get();
    }
}

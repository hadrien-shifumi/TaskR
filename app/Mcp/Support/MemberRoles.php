<?php

namespace App\Mcp\Support;

use App\Enums\WorkspaceRole;
use App\Models\User;

/**
 * Request-scoped memo of the workspace roles a user holds. The catalog
 * visibility filter asks it once per registered tool (57 times per
 * tools/list); the membership query must run once per request instead.
 */
final class MemberRoles
{
    /**
     * @var array<int|string, list<WorkspaceRole>>
     */
    private array $memo = [];

    /**
     * True when the user holds any of the wanted roles in at least one
     * workspace. Tools are workspace-generic, so the best role across
     * memberships decides the catalog: a guest of workspace B who owns
     * workspace A still needs the owner tools.
     */
    public function holdsAny(User $user, WorkspaceRole ...$wanted): bool
    {
        $this->memo[$user->id] ??= $user->memberships()
            ->pluck('role')
            ->map(fn (mixed $role): ?WorkspaceRole => $role instanceof WorkspaceRole
                ? $role
                : WorkspaceRole::tryFrom((string) $role))
            ->filter()
            ->unique()
            ->values()
            ->all();

        foreach ($wanted as $role) {
            if (in_array($role, $this->memo[$user->id], true)) {
                return true;
            }
        }

        return false;
    }
}

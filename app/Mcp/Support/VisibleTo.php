<?php

namespace App\Mcp\Support;

use App\Enums\WorkspaceRole;
use Attribute;

/**
 * Declares the workspace roles a tool is useful for. Restricted tools are
 * dropped from tools/list for agents that hold none of the required roles
 * in any of their workspaces, so a guest never discovers a tool that could
 * only answer "forbidden". Call-time authorization stays the real guard.
 *
 * Usage on a tool class: #[VisibleTo(WorkspaceRole::Owner, WorkspaceRole::Member)]
 * Tools without the attribute stay visible to every membership.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class VisibleTo
{
    /**
     * @var list<WorkspaceRole>
     */
    public readonly array $roles;

    public function __construct(WorkspaceRole ...$roles)
    {
        $this->roles = array_values($roles);
    }
}

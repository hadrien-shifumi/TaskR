<?php

namespace App\Support;

use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\KnowledgePage;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;

/**
 * Holds the workspace resolved for the current request (or null).
 *
 * Bound as a singleton; set by the ResolveCurrentWorkspace middleware.
 * All workspace-scoped models filter themselves against this context
 * via the BelongsToWorkspace trait global scope.
 *
 * Also the authorization hub: canUseWorkspace is the belonging check
 * (any membership, guests included), isFullMember excludes guests from
 * workspace-wide surfaces, and accessibleProjectIds resolves a guest's
 * scope from its project_members roster rows (null = unrestricted).
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

    /**
     * Belonging check: any membership (owner, member or guest) may
     * navigate the workspace. The per-project scope is enforced
     * separately, by accessibleProjectIds and its canAccess helpers.
     */
    public function canUseWorkspace(User $user): bool
    {
        if ($this->workspace === null) {
            return false;
        }

        return $this->workspace->hasMember($user);
    }

    /**
     * Membership with an unrestricted scope: owner or member.
     * Guests are excluded from workspace-wide surfaces.
     */
    public function isFullMember(User $user): bool
    {
        if ($this->workspace === null) {
            return false;
        }

        return ! in_array($this->workspace->roleOf($user), [WorkspaceRole::Guest, null], true);
    }

    /**
     * Project ids the user may access, or null for an unrestricted
     * scope (owner and member). A guest's scope is its project_members
     * roster; a non-member sees nothing.
     *
     * @return list<string>|null
     */
    public function accessibleProjectIds(User $user): ?array
    {
        if ($this->workspace === null) {
            return [];
        }

        $role = $this->workspace->roleOf($user);

        if ($role === null) {
            return [];
        }

        if ($role !== WorkspaceRole::Guest) {
            return null;
        }

        return ProjectMember::query()
            ->where('user_id', $user->getKey())
            ->pluck('project_id')
            ->all();
    }

    public function canViewProject(User $user, Project $project): bool
    {
        $scope = $this->accessibleProjectIds($user);

        return $scope === null || in_array($project->getKey(), $scope, true);
    }

    public function canAccessIssue(User $user, Issue $issue): bool
    {
        // Issues always belong to a project: check the scope by id,
        // without lazy-loading the relation on every check.
        $scope = $this->accessibleProjectIds($user);

        return $scope === null || in_array($issue->project_id, $scope, true);
    }

    /**
     * Workspace-level pages (no project) are reserved to full members;
     * project pages follow the user's project scope.
     */
    public function canAccessKnowledgePage(User $user, KnowledgePage $page): bool
    {
        if ($page->project_id === null) {
            return $this->isFullMember($user);
        }

        return $this->canViewProject($user, $page->project);
    }
}

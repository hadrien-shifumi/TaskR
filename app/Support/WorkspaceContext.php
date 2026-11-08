<?php

namespace App\Support;

use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\KnowledgePage;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\ModelNotFoundException;

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

    /**
     * Per-user memo of the resolved project scopes. A request resolves the
     * acting user's scope many times (list scoping, mention parsing, health
     * guards); the first resolution is cached per user until the context
     * switches workspaces. Null means "unrestricted" (owner or member), so
     * the memo must distinguish a cached null from an unresolved key.
     *
     * @var array<int|string, list<string>|null>
     */
    protected array $accessibleProjectScopes = [];

    public function set(?Workspace $workspace): void
    {
        $this->workspace = $workspace;
        $this->accessibleProjectScopes = [];
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
     * roster; a non-member sees nothing. Resolved once per user for the
     * lifetime of the current workspace binding.
     *
     * @return list<string>|null
     */
    public function accessibleProjectIds(User $user): ?array
    {
        if ($this->workspace === null) {
            return [];
        }

        $key = $user->getKey();

        if (! array_key_exists($key, $this->accessibleProjectScopes)) {
            $this->accessibleProjectScopes[$key] = $this->resolveAccessibleProjectIds($user, $this->workspace);
        }

        return $this->accessibleProjectScopes[$key];
    }

    /**
     * @return list<string>|null
     */
    private function resolveAccessibleProjectIds(User $user, Workspace $workspace): ?array
    {
        $role = $workspace->roleOf($user);

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

    /**
     * Anti-probing guard for a single-project surface (board, health,
     * members): an out-of-scope project must fail exactly like a
     * non-existent one — 404 on the web, not_found through MCP — never a
     * 403 that would confirm the project exists.
     */
    public function abortUnlessProjectAccessible(?User $user, Project $project): void
    {
        if ($user === null || ! $this->canViewProject($user, $project)) {
            throw (new ModelNotFoundException)->setModel(Project::class, [$project->getKey()]);
        }
    }

    public function canAccessIssue(User $user, Issue $issue): bool
    {
        // Issues always belong to a project: check the scope by id,
        // without lazy-loading the relation on every check.
        $scope = $this->accessibleProjectIds($user);

        return $scope === null || in_array($issue->project_id, $scope, true);
    }

    /**
     * Anti-probing guard for a single-issue surface (detail page, CRUD,
     * comments, time entries): an out-of-scope issue must fail exactly
     * like a non-existent one — 404 on the web, not_found through MCP.
     */
    public function abortUnlessIssueAccessible(?User $user, Issue $issue): void
    {
        if ($user === null || ! $this->canAccessIssue($user, $issue)) {
            throw (new ModelNotFoundException)->setModel(Issue::class, [$issue->getKey()]);
        }
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

    /**
     * Anti-probing guard for a single knowledge page (detail, CRUD): an
     * out-of-scope page must fail exactly like a non-existent one — 404 on
     * the web, not_found through MCP. Transverse pages belong to full
     * members only: a guest gets the same failure on them.
     */
    public function abortUnlessKnowledgePageAccessible(?User $user, KnowledgePage $page): void
    {
        if ($user === null || ! $this->canAccessKnowledgePage($user, $page)) {
            throw (new ModelNotFoundException)->setModel(KnowledgePage::class, [$page->getKey()]);
        }
    }

    public function canAccessMilestone(User $user, Milestone $milestone): bool
    {
        // Milestones always belong to a project: check the scope by id,
        // without lazy-loading the relation on every check.
        $scope = $this->accessibleProjectIds($user);

        return $scope === null || in_array($milestone->project_id, $scope, true);
    }

    /**
     * Anti-probing guard for a single milestone (CRUD, close, parent):
     * an out-of-scope milestone must fail exactly like a non-existent
     * one — 404 on the web, not_found through MCP.
     */
    public function abortUnlessMilestoneAccessible(?User $user, Milestone $milestone): void
    {
        if ($user === null || ! $this->canAccessMilestone($user, $milestone)) {
            throw (new ModelNotFoundException)->setModel(Milestone::class, [$milestone->getKey()]);
        }
    }
}

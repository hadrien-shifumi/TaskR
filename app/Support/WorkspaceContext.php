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
 * workspace-wide surfaces, and accessibleProjectIds resolves a user's
 * project access — their roster on the project teams, whatever their
 * workspace role (null = the unrestricted workspace owner).
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
     * scope (the workspace owner, who never carries team rows). Every
     * other role — member or guest — is roster-driven: the project team
     * is the single source of access, whatever role the line carries.
     * A non-member sees nothing. Resolved once per user for the
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
     * Forget the memoized project scopes (all users, or one). Roster
     * writes that need a fresh read on the same instance within a single
     * request call this after mutating the team data.
     */
    public function flushAccessibleProjectScope(?User $user = null): void
    {
        if ($user === null) {
            $this->accessibleProjectScopes = [];

            return;
        }

        unset($this->accessibleProjectScopes[$user->getKey()]);
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

        // The workspace owner keeps an implicit, unrestricted access to
        // every project — no team row is ever created for them.
        if ($role === WorkspaceRole::Owner) {
            return null;
        }

        // Members and guests alike are roster-driven: the project team
        // is the single source of access, whatever role the line
        // carries. The global workspace scope keeps the roster within
        // the current workspace.
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
     * Guard for issue mutations (create aside): the acting user must be
     * in the issue's project scope AND carry a contributing role on it.
     * A project Guest reads, comments and logs time but never writes:
     * the refusal is the same 404 / not_found as a non-existent issue —
     * never a 403 that would confirm the issue exists.
     */
    public function abortUnlessIssueContributor(?User $user, Issue $issue): void
    {
        $this->abortUnlessIssueAccessible($user, $issue);

        if (! $issue->project->isContributor($user)) {
            throw (new ModelNotFoundException)->setModel(Issue::class, [$issue->getKey()]);
        }
    }

    /**
     * Guard for project-level writes (issue creation): scope first, then
     * the contributing role. Same anti-probing refusal as above.
     */
    public function abortUnlessProjectContributor(?User $user, Project $project): void
    {
        $this->abortUnlessProjectAccessible($user, $project);

        if (! $project->isContributor($user)) {
            throw (new ModelNotFoundException)->setModel(Project::class, [$project->getKey()]);
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
     * Transverse knowledge pages (no project) are a full-member surface:
     * a scoped member keeps them — only a guest loses them. The list and
     * search actions need the rule as a boolean, without a page in hand.
     */
    public function seesTransverseKnowledgePages(User $user): bool
    {
        return $this->isFullMember($user);
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

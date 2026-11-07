<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class Workspace extends BaseModel
{
    use HasFactory;

    /**
     * Statuses are read on every issues surface and written through the
     * four owner paths only (create, update, delete, reorder — the
     * reorder flushes explicitly, its bulk update bypasses model events).
     */
    public const STATUSES_CACHE_TTL = 3600;

    protected $fillable = ['name', 'parents_as_containers'];

    protected $casts = [
        'parents_as_containers' => 'boolean',
    ];

    /**
     * Per-instance memo of the membership roles resolved on this workspace.
     * A request resolves the acting user's role many times (authorization,
     * Inertia shares, scoping): the first lookup is cached per user for the
     * lifetime of the instance. Instances are per-request (the context
     * singleton is reset by ResolveCurrentWorkspace), so the memo never
     * outlives the request that populated it.
     *
     * @var array<int|string, ?WorkspaceRole>
     */
    protected array $membershipRoles = [];

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'memberships')
            ->withPivot('role')
            ->using(Membership::class)
            ->withTimestamps();
    }

    /**
     * @return HasMany<Status, $this>
     */
    public function statuses(): HasMany
    {
        return $this->hasMany(Status::class)->orderBy('position');
    }

    /**
     * Workspace statuses from the per-workspace cache. Ordered by position,
     * exactly like the relation. Every write path flushes the cache (see
     * StatusObserver and ReorderStatuses).
     *
     * @return Collection<int, Status>
     */
    public function cachedStatuses()
    {
        return Cache::remember(
            "ws:{$this->getKey()}:statuses",
            self::STATUSES_CACHE_TTL,
            fn () => $this->statuses()->get(),
        );
    }

    public static function flushStatusesCache(string $workspaceId): void
    {
        Cache::forget("ws:{$workspaceId}:statuses");
    }

    /**
     * @return HasMany<Invitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * @return HasMany<Label, $this>
     */
    public function labels(): HasMany
    {
        return $this->hasMany(Label::class);
    }

    /**
     * @return HasMany<Issue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    /**
     * @return HasMany<IssueTemplate, $this>
     */
    public function issueTemplates(): HasMany
    {
        return $this->hasMany(IssueTemplate::class)->orderBy('name');
    }

    /**
     * @return HasMany<SavedView, $this>
     */
    public function savedViews(): HasMany
    {
        return $this->hasMany(SavedView::class)->orderBy('name');
    }

    public function hasMember(User $user): bool
    {
        return $this->membershipRole($user) !== null;
    }

    public function roleOf(User $user): ?WorkspaceRole
    {
        return $this->membershipRole($user);
    }

    public function hasOwner(User $user): bool
    {
        return $this->membershipRole($user) === WorkspaceRole::Owner;
    }

    public function isGuest(User $user): bool
    {
        return $this->membershipRole($user) === WorkspaceRole::Guest;
    }

    /**
     * Forget the memoized membership roles (all users, or one). Membership
     * writes that need a fresh read on the same instance within a single
     * request call this after mutating the memberships table.
     */
    public function flushMembershipRoleCache(?User $user = null): void
    {
        if ($user === null) {
            $this->membershipRoles = [];

            return;
        }

        unset($this->membershipRoles[$user->getKey()]);
    }

    private function membershipRole(User $user): ?WorkspaceRole
    {
        $key = $user->getKey();

        if (! array_key_exists($key, $this->membershipRoles)) {
            $this->membershipRoles[$key] = $this->memberships()
                ->where('user_id', $key)
                ->first()
                ?->role;
        }

        return $this->membershipRoles[$key];
    }
}

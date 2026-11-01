<?php

namespace App\Models;

use App\Enums\ProjectRole;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Scopes\WorkspaceScope;
use App\Support\HandleGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends BaseModel
{
    use BelongsToWorkspace, HasFactory;

    protected $fillable = ['workspace_id', 'name', 'handle', 'description', 'target_date', 'archived_at'];

    protected $casts = [
        'archived_at' => 'datetime',
        'target_date' => 'date',
    ];

    protected static function booted(): void
    {
        // Project handles are mentionable and unique per workspace: they
        // derive from the name on first create (an explicit value wins).
        static::creating(function (Project $project): void {
            if ($project->handle === null) {
                $project->handle = HandleGenerator::forProject($project->name, (string) $project->workspace_id);
            }
        });
    }

    /**
     * @return HasMany<Issue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    public function issuesCount(): int
    {
        return (int) $this->issues()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->count();
    }

    /**
     * Project roster: users with a role on this project.
     *
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')
            ->withPivot('role')
            ->using(ProjectMember::class)
            ->withTimestamps();
    }

    /**
     * @return HasMany<ProjectMember, $this>
     */
    public function projectMembers(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    /**
     * Get the project role of a user, or null if not in the roster.
     */
    public function roleOf(User $user): ?ProjectRole
    {
        $membership = $this->projectMembers()
            ->where('user_id', $user->getKey())
            ->first();

        return $membership?->role;
    }

    /**
     * Is the user a manager of this project (owner or manager role)?
     */
    public function isManager(User $user): bool
    {
        $role = $this->roleOf($user);

        return $role === ProjectRole::Owner || $role === ProjectRole::Manager;
    }

    /**
     * Can the user manage this project's roster?
     * Workspace owner is a super-manager; project owner/manager can also manage.
     */
    public function canManage(User $user): bool
    {
        // Workspace owner is always a super-manager
        if ($this->workspace->hasOwner($user)) {
            return true;
        }

        return $this->isManager($user);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}

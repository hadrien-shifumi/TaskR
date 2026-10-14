<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workspace extends BaseModel
{
    use HasFactory;

    protected $fillable = ['name'];

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

    public function hasMember(User $user): bool
    {
        return $this->members()->where('users.id', $user->getKey())->exists();
    }

    public function roleOf(User $user): ?WorkspaceRole
    {
        $membership = $this->memberships()
            ->where('user_id', $user->getKey())
            ->first();

        return $membership?->role;
    }

    public function hasOwner(User $user): bool
    {
        return $this->roleOf($user) === WorkspaceRole::Owner;
    }
}

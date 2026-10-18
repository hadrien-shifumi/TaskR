<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Invitation extends BaseModel
{
    use BelongsToWorkspace, HasFactory;

    protected $fillable = ['workspace_id', 'email', 'role', 'token', 'expires_at', 'accepted_at'];

    protected $casts = [
        'role' => WorkspaceRole::class,
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    /**
     * The projects announced to the invitee: the guest's perimeter, from
     * which the roster rows are created on acceptance.
     *
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'invitation_project');
    }

    /**
     * Persist the guest scope announced by the invitation. Rows are created
     * through the pivot model so the ULID id is generated — attach() inserts
     * raw pivot rows and would leave it null.
     *
     * @param  array<int, string>  $projectIds
     */
    public function attachScope(array $projectIds): void
    {
        foreach (array_values($projectIds) as $projectId) {
            InvitationProject::create([
                'invitation_id' => $this->getKey(),
                'project_id' => $projectId,
            ]);
        }
    }

    public function getRouteKeyName(): string
    {
        return 'token';
    }

    /**
     * Invitations are accepted cross-workspace by design (the invitee may not
     * be a member of — or have any workspace context for — the inviting one),
     * so route binding bypasses the workspace global scope.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return static::withoutWorkspaceScope()
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return $this->accepted_at === null && ! $this->isExpired();
    }
}

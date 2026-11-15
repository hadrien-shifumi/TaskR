<?php

namespace App\Models;

use App\Enums\ProjectRole;
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
     * The projects announced to the invitee, each with the project role
     * the acceptance will land them with on that project's team.
     *
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'invitation_project')
            ->using(InvitationProject::class)
            ->withPivot('role');
    }

    /**
     * Persist the projects announced by the invitation, each with its
     * project role. Rows are created through the pivot model so the ULID
     * id is generated — attach() inserts raw pivot rows and would leave
     * it null.
     *
     * @param  array<int, array{project_id: string, role: string}>  $projects
     */
    public function attachProjects(array $projects): void
    {
        foreach (array_values($projects) as $project) {
            InvitationProject::create([
                'invitation_id' => $this->getKey(),
                'project_id' => $project['project_id'],
                'role' => $project['role'],
            ]);
        }
    }

    /**
     * Announce the given projects with the plain member role — the legacy
     * shape of an invitation perimeter without per-project roles.
     *
     * @param  array<int, string>  $projectIds
     */
    public function attachScope(array $projectIds): void
    {
        $this->attachProjects(array_map(
            static fn (string $projectId): array => [
                'project_id' => $projectId,
                'role' => ProjectRole::Member->value,
            ],
            array_values($projectIds),
        ));
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

<?php

namespace App\Presenters\Workspaces;

use App\Enums\ProjectRole;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;

class MemberPresenter
{
    /**
     * Row of the workspace member list.
     *
     * @return array{id: int, name: string, email: string, role: string, isScoped: bool, scopeProjects: array<int, array{id: string, name: string, owned: bool}>, joinedAt: string|null}
     */
    public static function membership(Membership $membership): array
    {
        return [
            'id' => $membership->user->id,
            'name' => $membership->user->name,
            'email' => $membership->user->email,
            'role' => $membership->role->value,
            'isScoped' => (bool) $membership->is_scoped,
            'scopeProjects' => $membership->is_scoped ? self::scopeProjects($membership) : [],
            'joinedAt' => $membership->created_at?->toISOString(),
        ];
    }

    /**
     * Row of the pending invitations list.
     *
     * @return array{id: string, email: string, role: string, projects: array<int, string>, status: string, invitedAt: string|null}
     */
    public static function invitation(Invitation $invitation): array
    {
        $projects = $invitation->relationLoaded('projects')
            ? $invitation->projects->sortBy('name')->pluck('name')->values()->all()
            : $invitation->projects()->orderBy('name')->pluck('name')->all();

        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'projects' => $projects,
            'status' => $invitation->isExpired() ? 'expired' : 'sent',
            'invitedAt' => $invitation->created_at?->toISOString(),
        ];
    }

    /**
     * The effective perimeter shown for a scoped member: the granted
     * projects plus the projects they own — both are visible to them, so
     * the members page never announces less than the member actually sees.
     * Owned rows are flagged so the pilotage UI keeps them in the list.
     *
     * @return array<int, array{id: string, name: string, owned: bool}>
     */
    private static function scopeProjects(Membership $membership): array
    {
        $granted = $membership->projectScopeIds();
        $owned = ProjectMember::query()
            ->where('user_id', $membership->user_id)
            ->where('role', ProjectRole::Owner->value)
            ->pluck('project_id')
            ->all();

        return Project::query()
            ->whereIn('id', array_values(array_unique([...$granted, ...$owned])))
            ->orderBy('name')
            ->get()
            ->map(fn (Project $project) => [
                'id' => $project->getKey(),
                'name' => $project->name,
                'owned' => in_array($project->getKey(), $owned, true),
            ])
            ->values()
            ->all();
    }
}

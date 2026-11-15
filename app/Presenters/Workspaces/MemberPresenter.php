<?php

namespace App\Presenters\Workspaces;

use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Project;

class MemberPresenter
{
    /**
     * Row of the workspace member list.
     *
     * @return array{id: int, name: string, email: string, role: string, joinedAt: string|null}
     */
    public static function membership(Membership $membership): array
    {
        return [
            'id' => $membership->user->id,
            'name' => $membership->user->name,
            'email' => $membership->user->email,
            'role' => $membership->role->value,
            'joinedAt' => $membership->created_at?->toISOString(),
        ];
    }

    /**
     * Row of the pending invitations list: each announced project with
     * the project role the invitee will land with at acceptance.
     *
     * @return array{id: string, email: string, role: string, projects: array<int, array{name: string, role: string}>, status: string, invitedAt: string|null}
     */
    public static function invitation(Invitation $invitation): array
    {
        $projects = $invitation->relationLoaded('projects')
            ? $invitation->projects->sortBy('name')->values()
            : $invitation->projects()->orderBy('name')->get();

        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'projects' => $projects
                ->map(static fn (Project $project): array => [
                    'name' => $project->name,
                    'role' => $project->pivot->role->value,
                ])
                ->all(),
            'status' => $invitation->isExpired() ? 'expired' : 'sent',
            'invitedAt' => $invitation->created_at?->toISOString(),
        ];
    }
}

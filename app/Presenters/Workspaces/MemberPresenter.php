<?php

namespace App\Presenters\Workspaces;

use App\Models\Invitation;
use App\Models\Membership;

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
}

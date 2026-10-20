<?php

namespace App\Presenters\Projects;

use App\Models\ProjectMember;
use App\Models\User;

class ProjectMemberPresenter
{
    /**
     * Row of the project roster.
     *
     * @return array{id: int, name: string, email: string, role: string, joinedAt: string|null}
     */
    public static function rosterMember(ProjectMember $member): array
    {
        return [
            'id' => $member->user->id,
            'name' => $member->user->name,
            'email' => $member->user->email,
            'role' => $member->role->value,
            'joinedAt' => $member->created_at?->toISOString(),
        ];
    }

    /**
     * Option of the "available members" picker — contact included.
     *
     * @param  User|object  $user
     * @return array{id: int, name: string, email: string}
     */
    public static function contact(object $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email];
    }

    /**
     * Minimal person shape (id + name) shared by assignees, member
     * pickers and board people — accepts a User or any object exposing
     * public id and name (joined rows hydrate as stdClass).
     *
     * @param  User|object  $user
     * @return array{id: int, name: string}
     */
    public static function person(object $user): array
    {
        return ['id' => $user->id, 'name' => $user->name];
    }
}

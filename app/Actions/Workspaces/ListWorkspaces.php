<?php

namespace App\Actions\Workspaces;

use App\Models\User;
use Lorisleiva\Actions\Concerns\AsObject;

class ListWorkspaces
{
    use AsObject;

    /**
     * Workspaces the user belongs to, with their role in each.
     *
     * @return array<int, array{id: string, name: string, role: string}>
     */
    public function handle(User $user): array
    {
        return $user->memberships()
            ->with('workspace')
            ->orderBy('name')
            ->get()
            ->map(fn ($membership) => [
                'id' => $membership->workspace->id,
                'name' => $membership->workspace->name,
                'role' => $membership->role->value,
            ])
            ->values()
            ->all();
    }
}

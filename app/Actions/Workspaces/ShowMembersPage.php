<?php

namespace App\Actions\Workspaces;

use App\Models\User;
use App\Models\Workspace;
use App\Responders\Workspaces\MembersPageResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

class ShowMembersPage
{
    use AsController;
    use AsObject;

    public function authorize(): bool
    {
        $user = request()->user();

        return $user instanceof User && app(WorkspaceContext::class)->isFullMember($user);
    }

    /**
     * Present the members of the workspace, their pending invitations with
     * the invited role and guest scope, and the active projects available
     * to the invite dialog.
     *
     * @return array{members: array<int, array<string, mixed>>, invitations: array<int, array<string, mixed>>, projects: array<int, array<string, mixed>>}
     */
    public function handle(Workspace $workspace): array
    {
        $members = $workspace->memberships()
            ->with('user')
            ->orderBy('created_at')
            ->orderBy('user_id')
            ->get()
            ->map(fn ($membership) => [
                'id' => $membership->user->id,
                'name' => $membership->user->name,
                'email' => $membership->user->email,
                'role' => $membership->role->value,
                'joinedAt' => $membership->created_at?->toISOString(),
            ])
            ->values()
            ->all();

        $invitations = $workspace->invitations()
            ->whereNull('accepted_at')
            ->with('projects')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($invitation) => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'projects' => $invitation->projects()->orderBy('name')->pluck('name')->all(),
                'status' => $invitation->isExpired() ? 'expired' : 'sent',
                'invitedAt' => $invitation->created_at?->toISOString(),
            ])
            ->values()
            ->all();

        $projects = $workspace->projects()
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get()
            ->map(fn ($project) => [
                'id' => $project->id,
                'name' => $project->name,
            ])
            ->values()
            ->all();

        return [
            'members' => $members,
            'invitations' => $invitations,
            'projects' => $projects,
        ];
    }

    public function asController(Request $request, MembersPageResponder $responder): Response
    {
        $workspace = app(WorkspaceContext::class)->getOrFail();

        return $responder->toResponse($request, $this->handle($workspace));
    }
}

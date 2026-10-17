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
     * Present the members of the workspace and their pending invitations.
     *
     * @return array{members: array<int, array<string, mixed>>, invitations: array<int, array<string, mixed>>}
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
            ->orderBy('created_at')
            ->get()
            ->map(fn ($invitation) => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'status' => $invitation->isExpired() ? 'expired' : 'sent',
                'invitedAt' => $invitation->created_at?->toISOString(),
            ])
            ->values()
            ->all();

        return [
            'members' => $members,
            'invitations' => $invitations,
        ];
    }

    public function asController(Request $request, MembersPageResponder $responder): Response
    {
        $workspace = app(WorkspaceContext::class)->getOrFail();

        return $responder->toResponse($request, $this->handle($workspace));
    }
}

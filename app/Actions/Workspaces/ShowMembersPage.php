<?php

namespace App\Actions\Workspaces;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Models\Workspace;
use App\Presenters\Projects\ProjectPresenter;
use App\Presenters\Workspaces\MemberPresenter;
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
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserIsFullMember();
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
            ->map(fn ($membership) => MemberPresenter::membership($membership))
            ->values()
            ->all();

        $invitations = $workspace->invitations()
            ->whereNull('accepted_at')
            ->with('projects')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($invitation) => MemberPresenter::invitation($invitation))
            ->values()
            ->all();

        $projects = $workspace->projects()
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get()
            ->map(fn ($project) => ProjectPresenter::option($project))
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

<?php

namespace App\Actions\Projects;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Presenters\Projects\ProjectMemberPresenter;
use App\Presenters\Projects\ProjectPresenter;
use App\Responders\Projects\ProjectMemberPageResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

class ShowProjectMembers
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    /**
     * List the project roster and the workspace members available for onboarding.
     *
     * @return array{project: array<string, mixed>, members: array<int, array<string, mixed>>, availableMembers: array<int, array<string, mixed>>, canManage: bool, assignableRoles: list<string>}
     */
    public function handle(Project $project, ?User $user = null): array
    {
        // Out-of-scope projects fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        app(WorkspaceContext::class)->abortUnlessProjectAccessible($user, $project);

        $workspace = $project->workspace;

        $members = $project->projectMembers()
            ->with('user')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($pm) => ProjectMemberPresenter::rosterMember(
                $pm,
                $user instanceof User && $project->canPilotRole($user, $pm->role),
            ))
            ->values()
            ->all();

        $rosterUserIds = $project->projectMembers()->pluck('user_id')->all();

        $availableMembers = $workspace->members()
            ->whereNotIn('users.id', $rosterUserIds)
            ->orderBy('name')
            ->get(['users.id', 'users.name', 'users.email'])
            ->map(fn ($u) => ProjectMemberPresenter::contact($u))
            ->values()
            ->all();

        // N+1 (D9–D11): the roles the actor may assign are the strictly
        // lower ones — the workspace owner bypasses and assigns every
        // role but owner (the single-owner rule refuses a second one).
        $assignableRoles = [];
        foreach ([ProjectRole::Manager, ProjectRole::Member, ProjectRole::Guest] as $role) {
            if ($user instanceof User && $project->canPilotRole($user, $role)) {
                $assignableRoles[] = $role->value;
            }
        }

        return [
            'project' => ProjectPresenter::option($project),
            'members' => $members,
            'availableMembers' => $availableMembers,
            'canManage' => $user instanceof User && $project->canManage($user),
            'assignableRoles' => $assignableRoles,
        ];
    }

    public function asController(Request $request, Project $project, ProjectMemberPageResponder $responder): Response
    {
        return $responder->toResponse($request, $this->handle($project, $request->user()));
    }
}

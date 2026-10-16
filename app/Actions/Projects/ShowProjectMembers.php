<?php

namespace App\Actions\Projects;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
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

    public function authorize(): bool
    {
        $user = request()->user();

        return $user instanceof User && app(WorkspaceContext::class)->isMember($user);
    }

    /**
     * List the project roster and the workspace members available for onboarding.
     *
     * @return array{project: array<string, mixed>, members: array<int, array<string, mixed>>, availableMembers: array<int, array<string, mixed>>, canManage: bool}
     */
    public function handle(Project $project): array
    {
        $user = request()->user();
        $workspace = $project->workspace;

        $members = $project->projectMembers()
            ->with('user')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($pm) => [
                'id' => $pm->user->id,
                'name' => $pm->user->name,
                'email' => $pm->user->email,
                'role' => $pm->role->value,
                'joinedAt' => $pm->created_at?->toISOString(),
            ])
            ->values()
            ->all();

        $rosterUserIds = $project->projectMembers()->pluck('user_id')->all();

        $availableMembers = $workspace->members()
            ->whereNotIn('users.id', $rosterUserIds)
            ->orderBy('name')
            ->get(['users.id', 'users.name', 'users.email'])
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
            ])
            ->values()
            ->all();

        return [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
            ],
            'members' => $members,
            'availableMembers' => $availableMembers,
            'canManage' => $user instanceof User && $project->canManage($user),
        ];
    }

    public function asController(Request $request, Project $project, ProjectMemberPageResponder $responder): Response
    {
        return $responder->toResponse($request, $this->handle($project));
    }
}

<?php

namespace App\Actions\Projects;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Models\Project;
use App\Models\User;
use App\Presenters\Issues\IssuePresenter;
use App\Presenters\Projects\ProjectPresenter;
use App\Responders\Projects\ProjectArchivesResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

class ShowProjectArchives
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    /**
     * Archived issues of a project, newest archive first. Read access follows
     * the perimeter rules; unarchiving stays owner-only.
     *
     * @return array{project: array<string, mixed>, issues: array<int, array<string, mixed>>, isOwner: bool}
     */
    public function handle(Project $project, ?User $user = null): array
    {
        // Out-of-scope projects fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        app(WorkspaceContext::class)->abortUnlessProjectAccessible($user, $project);

        $workspace = app(WorkspaceContext::class)->getOrFail();

        $issues = $project->issues()
            ->with(['status', 'labels', 'assignees'])
            ->archived()
            ->orderByDesc('archived_at')
            ->get();

        return [
            'project' => ProjectPresenter::summary($project),
            'issues' => $issues->map(fn ($issue) => IssuePresenter::card($issue))->values()->all(),
            'isOwner' => $user !== null && $workspace->hasOwner($user),
        ];
    }

    public function asController(Request $request, Project $project, ProjectArchivesResponder $responder): Response
    {
        $data = $this->handle($project, $request->user());

        return $responder->toResponse($request, $data);
    }
}

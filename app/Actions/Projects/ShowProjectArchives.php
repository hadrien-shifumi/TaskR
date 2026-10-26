<?php

namespace App\Actions\Projects;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Presenters\Issues\IssuePresenter;
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
     * The archive of a project: every archived issue, most recently
     * archived first. Unarchiving is an owner-only management action —
     * the payload exposes the flag so the view can render the button.
     *
     * @return array{project: array<string, mixed>, issues: array<int, array<string, mixed>>, canUnarchive: bool}
     */
    public function handle(Project $project, ?User $user = null): array
    {
        // Out-of-scope projects fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP callers always pass the actor.
        app(WorkspaceContext::class)->abortUnlessProjectAccessible($user, $project);

        $workspace = app(WorkspaceContext::class)->getOrFail();

        $issues = $project->issues()
            ->archived()
            ->with(['status', 'assignees', 'labels'])
            ->orderByDesc('archived_at')
            ->get()
            ->map(fn (Issue $issue) => [
                ...IssuePresenter::card($issue),
                'archivedAt' => $issue->archived_at->toISOString(),
            ])
            ->values()
            ->all();

        return [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'targetDate' => $project->target_date?->toDateString(),
            ],
            'issues' => $issues,
            'canUnarchive' => $user !== null && $workspace->hasOwner($user),
        ];
    }

    public function asController(Request $request, Project $project, ProjectArchivesResponder $responder): Response
    {
        $data = $this->handle($project, $request->user());

        return $responder->toResponse($request, $data);
    }
}

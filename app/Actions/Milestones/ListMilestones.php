<?php

namespace App\Actions\Milestones;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\User;
use App\Presenters\Milestones\MilestonePresenter;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class ListMilestones
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    /**
     * Every milestone of a project, ordered by title — the option feed
     * of the issue dialog and the project's milestone surfaces. Guests
     * read within their perimeter like on any project surface.
     *
     * @return array<int, array<string, mixed>>
     */
    public function handle(Project $project, ?User $user = null): array
    {
        // Out-of-scope projects fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessProjectAccessible($user, $project);
        }

        return $project->milestones()
            ->with('creator')
            ->withIssueCounts()
            ->orderBy('title')
            ->get()
            ->map(fn (Milestone $milestone) => MilestonePresenter::card($milestone))
            ->values()
            ->all();
    }

    public function asController(ActionRequest $request, Project $project): JsonResponse
    {
        return response()->json([
            'milestones' => $this->handle($project, $request->user()),
        ]);
    }
}

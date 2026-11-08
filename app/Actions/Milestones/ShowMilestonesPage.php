<?php

namespace App\Actions\Milestones;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\User;
use App\Presenters\Milestones\MilestonePresenter;
use App\Presenters\Projects\ProjectPresenter;
use App\Responders\Milestones\MilestoneListPageResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

class ShowMilestonesPage
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    /**
     * Present the Échéances tab of a project: every milestone as a card
     * carrying its parent and its issue progression, plus the acting
     * user's permissions. Writing milestones (create, edit, close,
     * parent, attach) follows the contributor perimeter; deletion
     * follows the roster-management one.
     *
     * @return array{project: array<string, mixed>, milestones: array<int, array<string, mixed>>, canContribute: bool, canManage: bool}
     */
    public function handle(Project $project, ?User $user = null): array
    {
        // Out-of-scope projects fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        app(WorkspaceContext::class)->abortUnlessProjectAccessible($user, $project);

        $milestones = $project->milestones()
            ->with('creator')
            ->withIssueCounts()
            ->orderBy('title')
            ->get();

        return [
            'project' => ProjectPresenter::summary($project),
            'milestones' => $milestones
                ->map(fn (Milestone $milestone) => MilestonePresenter::card($milestone))
                ->values()
                ->all(),
            'canContribute' => $user instanceof User && $project->isContributor($user),
            'canManage' => $user instanceof User && $project->canManage($user),
        ];
    }

    public function asController(Request $request, Project $project, MilestoneListPageResponder $responder): Response
    {
        return $responder->toResponse($request, $this->handle($project, $request->user()));
    }
}

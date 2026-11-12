<?php

namespace App\Actions\Milestones;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Actions\Issues\LoadIssueKnowledgePages;
use App\Models\Milestone;
use App\Models\User;
use App\Presenters\Milestones\MilestonePresenter;
use App\Presenters\Projects\ProjectPresenter;
use App\Responders\Milestones\MilestoneShowPageResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

class ShowMilestonePage
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    /**
     * Present the detail page of a milestone: the fiche (parent, dates,
     * progression), its unarchived issues, its child milestones and
     * the same permission props as the project's Échéances tab. The set-
     * parent dialog fetches its live candidates through the milestones
     * search endpoint; cycle and cross-project rules stay enforced by the
     * mutation action.
     *
     * @return array{milestone: array<string, mixed>, project: array<string, mixed>, canContribute: bool, canManage: bool}
     */
    public function handle(Milestone $milestone, ?User $user = null): array
    {
        // Out-of-scope milestones fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        app(WorkspaceContext::class)->abortUnlessMilestoneAccessible($user, $milestone);

        $milestone->load('creator');
        $milestone->loadCount(Milestone::issueCounts());

        $parent = $milestone->parent()->first();

        $children = $milestone->children()
            ->with('creator')
            ->withIssueCounts()
            ->orderBy('title')
            ->get();

        $issues = $milestone->issues()
            ->unarchived()
            ->with(['project', 'status', 'labels', 'assignees', 'blockedBy.status'])
            ->with(['knowledgePages' => LoadIssueKnowledgePages::cardConstraint($user)])
            ->orderBy('created_at')
            ->get();

        return [
            'milestone' => MilestonePresenter::detail($milestone, $parent, $children, $issues),
            'project' => ProjectPresenter::summary($milestone->project),
            'canContribute' => $user instanceof User && $milestone->project->isContributor($user),
            'canManage' => $user instanceof User && $milestone->project->canManage($user),
        ];
    }

    public function asController(Request $request, Milestone $milestone, MilestoneShowPageResponder $responder): Response
    {
        return $responder->toResponse($request, $this->handle($milestone, $request->user()));
    }
}

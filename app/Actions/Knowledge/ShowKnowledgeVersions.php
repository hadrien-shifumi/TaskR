<?php

namespace App\Actions\Knowledge;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Models\KnowledgePage;
use App\Models\User;
use App\Presenters\Projects\ProjectPresenter;
use App\Responders\Knowledge\KnowledgePageResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

class ShowKnowledgeVersions
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    /**
     * Version history page payload: the page detail, its version list,
     * plus the sidebar data (pages tree and projects) shared with the
     * knowledge index.
     *
     * @return array{page: array<string, mixed>, versions: array<int, array<string, mixed>>, pages: array<int, array<string, mixed>>, projects: array<int, array<string, mixed>>}
     */
    public function handle(KnowledgePage $page, ?User $user = null): array
    {
        $workspace = app(WorkspaceContext::class)->getOrFail();

        // Out-of-scope pages fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP callers always pass the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessKnowledgePageAccessible($user, $page);
        }

        $scope = $user instanceof User
            ? app(WorkspaceContext::class)->accessibleProjectIds($user)
            : null;

        return [
            'page' => GetKnowledgePage::run($page, $user),
            'versions' => ListKnowledgePageVersions::run($page, $user),
            'pages' => ListKnowledgePages::run($workspace, $user, $page->project_id ?? 'transverse'),
            'projects' => $workspace->projects()
                ->when($scope !== null, fn ($query) => $query->whereIn('id', $scope))
                ->orderBy('name')
                ->get()
                ->map(fn ($project) => ProjectPresenter::option($project))
                ->all(),
        ];
    }

    public function asController(Request $request, KnowledgePage $page, KnowledgePageResponder $responder): Response
    {
        $data = $this->handle($page, $request->user());

        return $responder->versions($request, $data);
    }
}

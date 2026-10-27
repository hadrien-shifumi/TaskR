<?php

namespace App\Actions\Knowledge;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Models\User;
use App\Models\Workspace;
use App\Presenters\Projects\ProjectPresenter;
use App\Responders\Knowledge\KnowledgePageResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

class ShowKnowledgeTrash
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    /**
     * Trash page payload: trashed pages for the list, plus the sidebar
     * data (pages tree and projects) shared with the knowledge index.
     *
     * @return array{trashedPages: array<int, array<string, mixed>>, pages: array<int, array<string, mixed>>, projects: array<int, array<string, mixed>>}
     */
    public function handle(Workspace $workspace, ?User $user = null): array
    {
        $scope = $user instanceof User
            ? app(WorkspaceContext::class)->accessibleProjectIds($user)
            : null;

        return [
            'trashedPages' => ListTrashedKnowledgePages::run($workspace, $user),
            'pages' => ListKnowledgePages::run($workspace, $user),
            'projects' => $workspace->projects()
                ->when($scope !== null, fn ($query) => $query->whereIn('id', $scope))
                ->orderBy('name')
                ->get()
                ->map(fn ($project) => ProjectPresenter::option($project))
                ->all(),
        ];
    }

    public function asController(Request $request, KnowledgePageResponder $responder): Response
    {
        $data = $this->handle(app(WorkspaceContext::class)->getOrFail(), $request->user());

        return $responder->trash($request, $data);
    }
}

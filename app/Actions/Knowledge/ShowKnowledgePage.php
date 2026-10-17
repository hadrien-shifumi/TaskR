<?php

namespace App\Actions\Knowledge;

use App\Models\KnowledgePage;
use App\Models\User;
use App\Models\Workspace;
use App\Responders\Knowledge\KnowledgePageResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

class ShowKnowledgePage
{
    use AsController;
    use AsObject;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    /**
     * @return array{pages: array<int, array<string, mixed>>, projects: array<int, array<string, mixed>>, selected: array<string, mixed>|null}
     */
    public function handle(Workspace $workspace, ?KnowledgePage $selected = null): array
    {
        $user = request()->user();
        $scope = $user instanceof User
            ? app(WorkspaceContext::class)->accessibleProjectIds($user)
            : null;

        // Out-of-scope detail pages fail exactly like non-existent ones.
        if ($selected !== null && $user !== null) {
            app(WorkspaceContext::class)->abortUnlessKnowledgePageAccessible($user, $selected);
        }

        return [
            'pages' => ListKnowledgePages::run($workspace, $user),
            'projects' => $workspace->projects()
                ->when($scope !== null, fn ($query) => $query->whereIn('id', $scope))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($project): array => ['id' => $project->id, 'name' => $project->name])
                ->all(),
            'selected' => $selected !== null ? GetKnowledgePage::run($selected, $user) : null,
        ];
    }

    public function asController(Request $request, KnowledgePageResponder $responder, ?KnowledgePage $page = null): Response
    {
        $data = $this->handle(app(WorkspaceContext::class)->getOrFail(), $page);

        return $responder->toResponse($request, $data);
    }

    protected function currentUserCanUseWorkspace(): bool
    {
        $user = request()->user();

        return $user instanceof User && app(WorkspaceContext::class)->canUseWorkspace($user);
    }
}

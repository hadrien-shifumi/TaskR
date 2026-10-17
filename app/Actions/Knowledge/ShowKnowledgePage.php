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
        return [
            'pages' => ListKnowledgePages::run($workspace),
            'projects' => $workspace->projects()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($project): array => ['id' => $project->id, 'name' => $project->name])
                ->all(),
            'selected' => $selected !== null ? GetKnowledgePage::run($selected) : null,
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

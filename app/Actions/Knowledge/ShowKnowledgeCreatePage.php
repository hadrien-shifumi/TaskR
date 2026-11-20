<?php

namespace App\Actions\Knowledge;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Models\KnowledgePage;
use App\Models\Workspace;
use App\Presenters\Projects\ProjectPresenter;
use App\Responders\Knowledge\KnowledgePageResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dedicated full-screen creation page for knowledge pages — the roomy
 * replacement for the old create modal. The screen only ships the
 * perimeter's projects plus any prefill hints from the query string;
 * the write itself stays with CreateKnowledgePage.
 */
class ShowKnowledgeCreatePage
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function handle(Workspace $workspace, array $data): array
    {
        // A prefilled parent carries its title so the picker shows it
        // before any search: the prefill must be visible, not silent.
        $parentId = is_string($data['parent'] ?? null) ? $data['parent'] : null;
        $parentTitle = $parentId !== null
            ? KnowledgePage::query()
                ->where('workspace_id', $workspace->id)
                ->find($parentId)
                ?->title
            : null;

        return [
            'projects' => $workspace->projects()
                ->orderBy('name')
                ->get()
                ->map(fn ($project) => ProjectPresenter::option($project))
                ->all(),
            'prefillProject' => is_string($data['project'] ?? null) ? $data['project'] : null,
            'prefillParent' => $parentId,
            'prefillParentTitle' => $parentTitle,
            'prefillTitle' => is_string($data['title'] ?? null) ? $data['title'] : null,
        ];
    }

    public function asController(Request $request, KnowledgePageResponder $responder): Response
    {
        $data = $this->handle(
            app(WorkspaceContext::class)->getOrFail(),
            $request->query(),
        );

        return $responder->create($request, $data);
    }
}

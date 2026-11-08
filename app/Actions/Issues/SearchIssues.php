<?php

namespace App\Actions\Issues;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Models\Issue;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class SearchIssues
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'max:500'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:25'],
        ];
    }

    /**
     * The issues matching a free-text search (title, or an identifier
     * prefix), within the workspace and the reader's perimeter. The query
     * is the exact one of the issues page — a search result always exists
     * on the list too.
     *
     * @param  non-empty-string  $q
     * @return list<array{id: string, title: string, projectId: string}>
     */
    public function handle(Workspace $workspace, string $q, int $limit = 10, ?User $user = null): array
    {
        return app(ListIssues::class)
            ->filteredQuery($workspace, ['q' => $q], $user)
            ->limit($limit)
            ->get(['id', 'title', 'project_id'])
            ->map(fn (Issue $issue): array => [
                'id' => $issue->id,
                'title' => $issue->title,
                'projectId' => $issue->project_id,
            ])
            ->values()
            ->all();
    }

    public function asController(ActionRequest $request): JsonResponse
    {
        $workspace = app(WorkspaceContext::class)->getOrFail();

        $data = $request->validated();

        return response()->json($this->handle(
            $workspace,
            (string) $data['q'],
            (int) ($data['limit'] ?? 10),
            $request->user(),
        ));
    }
}

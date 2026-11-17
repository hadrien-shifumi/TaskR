<?php

namespace App\Actions\Knowledge;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Models\KnowledgePage;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class SearchKnowledgeParents
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
            // Empty query = browse the top of the tree (root pages).
            'q' => ['nullable', 'string', 'max:500'],
            // The page being edited: it and its descendants are removed
            // server-side so a cycle can never be selected.
            'exclude' => ['nullable', 'ulid'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    /**
     * The knowledge pages eligible as a parent, within the workspace and
     * the reader's perimeter — independent of the sidebar's project
     * filter. Each row carries its ancestor path (root → direct parent)
     * so the combobox can indent the hierarchy. The excluded page and
     * its whole subtree never come back.
     *
     * @param  non-empty-string  $q
     * @return list<array{id: string, title: string, projectId: string|null, path: list<array{id: string, title: string}>}>
     */
    public function handle(Workspace $workspace, string $q = '', ?string $excludeId = null, int $limit = 25, ?User $user = null): array
    {
        $context = app(WorkspaceContext::class);
        $scope = $user instanceof User ? $context->accessibleProjectIds($user) : null;
        $seesTransverse = ! $user instanceof User || $context->seesTransverseKnowledgePages($user);

        $excludedIds = [];
        if ($excludeId !== null) {
            $excluded = (new KnowledgePage)->withoutWorkspaceScope()->find($excludeId);
            if ($excluded !== null) {
                $excludedIds = [$excluded->id, ...$excluded->descendantIds()];
            }
        }

        return KnowledgePage::query()
            ->when($scope !== null, fn ($query) => $query->where(function ($query) use ($scope, $seesTransverse) {
                $query->whereIn('project_id', $scope);

                if ($seesTransverse) {
                    $query->orWhereNull('project_id');
                }
            }))
            ->when($q !== '', fn ($query) => $query->where('title', 'like', "%{$q}%"))
            ->when($q === '', fn ($query) => $query->whereNull('parent_id'))
            ->when($excludedIds !== [], fn ($query) => $query->whereNotIn('id', $excludedIds))
            ->orderBy('title')
            ->limit($limit)
            ->get()
            ->map(fn (KnowledgePage $page): array => [
                'id' => $page->id,
                'title' => $page->title,
                'projectId' => $page->project_id,
                'path' => $page->ancestorChain(),
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
            (string) ($data['q'] ?? ''),
            $data['exclude'] ?? null,
            (int) ($data['limit'] ?? 25),
            $request->user(),
        ));
    }
}

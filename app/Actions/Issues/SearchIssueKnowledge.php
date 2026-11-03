<?php

namespace App\Actions\Issues;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Actions\Knowledge\SearchKnowledge;
use App\Models\Issue;
use App\Models\User;
use App\Support\EmbeddingsNotConfigured;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class SearchIssueKnowledge
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function __construct(private SearchKnowledge $search) {}

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'max:500'],
            'project_id' => [
                'nullable', 'ulid',
                Rule::exists('projects', 'id')->where('workspace_id', app(WorkspaceContext::class)->id()),
            ],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    /**
     * Semantic search scoped to the pages linkable from this issue's
     * workspace: the guest project scope, the trash exclusion and the
     * out-of-scope project_id guard all come from SearchKnowledge —
     * never duplicated here.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws EmbeddingsNotConfigured
     */
    public function handle(Issue $issue, string $query, ?string $projectId = null, int $limit = 10, ?User $user = null): array
    {
        // Out-of-scope issues fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessIssueAccessible($user, $issue);
        }

        return $this->search->handle(app(WorkspaceContext::class)->getOrFail(), $query, $projectId, $limit, $user);
    }

    public function asController(ActionRequest $request, Issue $issue): JsonResponse
    {
        $data = $request->validated();

        try {
            $results = $this->handle(
                $issue,
                $data['q'],
                $data['project_id'] ?? null,
                (int) ($data['limit'] ?? 10),
                $request->user(),
            );

            return response()->json(['results' => $results]);
        } catch (EmbeddingsNotConfigured) {
            // Degraded mode mirrors the web search: 200 with an explicit
            // flag instead of a 422, so the link dialog keeps working.
            return response()->json(['results' => [], 'searchUnavailable' => true]);
        }
    }
}

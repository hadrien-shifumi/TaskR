<?php

namespace App\Actions\Knowledge;

use App\Models\KnowledgePage;
use App\Models\User;
use App\Models\Workspace;
use App\Responders\Knowledge\KnowledgePageResponder;
use App\Support\Embeddings;
use App\Support\EmbeddingsNotConfigured;
use App\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

class SearchKnowledge
{
    use AsController;
    use AsObject;

    public function __construct(private Embeddings $embeddings) {}

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
     * Embed the query, then rank the workspace's vectorized pages by cosine
     * similarity. The ranking seam is driver-dependent (pgvector operator in
     * production, exact cosine in PHP on sqlite for dev/tests) but the
     * contract is identical: ranked results with id, title, excerpt, score.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws EmbeddingsNotConfigured
     */
    public function handle(Workspace $workspace, string $query, ?string $projectId = null, int $limit = 10, ?User $user = null): array
    {
        // A guest searches strictly within their perimeter: transverse
        // pages and other projects' pages never surface in the results.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        $scope = $user instanceof User
            ? app(WorkspaceContext::class)->accessibleProjectIds($user)
            : null;

        if ($scope !== null && $projectId !== null && ! in_array($projectId, $scope, true)) {
            throw ValidationException::withMessages([
                'project_id' => [__('validation.knowledge.project_out_of_scope')],
            ]);
        }

        if (! $this->embeddings->enabled()) {
            throw new EmbeddingsNotConfigured;
        }

        $vector = $this->embeddings->embed($query);

        if (DB::connection()->getDriverName() === 'pgsql') {
            return $this->pgsqlRankedQuery($vector, $projectId, $scope)
                ->limit($limit)
                ->get()
                ->map(fn ($row): array => [
                    'id' => $row->id,
                    'title' => $row->title,
                    'excerpt' => $this->excerpt($row->body),
                    'score' => round((float) $row->similarity, 4),
                    'projectId' => $row->project_id,
                ])
                ->all();
        }

        return KnowledgePage::query()
            ->whereNotNull('embedding')
            ->when($projectId !== null, fn (Builder $builder) => $builder->where('project_id', $projectId))
            ->when($scope !== null, fn (Builder $builder) => $builder->whereIn('project_id', $scope))
            ->get()
            ->map(fn (KnowledgePage $page): array => [
                'page' => $page,
                'score' => $this->cosine($vector, $page->embedding ?? []),
            ])
            ->sortByDesc('score')
            ->values()
            ->take($limit)
            ->map(fn (array $ranked): array => [
                'id' => $ranked['page']->id,
                'title' => $ranked['page']->title,
                'excerpt' => $this->excerpt($ranked['page']->body),
                'score' => round($ranked['score'], 4),
                'projectId' => $ranked['page']->project_id,
            ])
            ->all();
    }

    public function asController(ActionRequest $request, KnowledgePageResponder $responder): Response
    {
        $workspace = app(WorkspaceContext::class)->getOrFail();
        $data = $request->validated();

        try {
            $payload = array_merge(ShowKnowledgePage::run($workspace, null, $request->user()), [
                'query' => $data['q'],
                'results' => $this->handle($workspace, $data['q'], $data['project_id'] ?? null, (int) ($data['limit'] ?? 10), $request->user()),
            ]);
        } catch (EmbeddingsNotConfigured) {
            $payload = array_merge(ShowKnowledgePage::run($workspace, null, $request->user()), [
                'query' => $data['q'],
                'searchUnavailable' => true,
            ]);
        }

        return $responder->toResponse($request, $payload);
    }

    /**
     * Production ranking path: pgvector cosine distance (`<=>`) — nearest
     * first, score = 1 - distance. Kept in one place so the grammar stays
     * assertable from the sqlite test suite.
     *
     * @param  array<int, float>  $vector
     * @return Builder<KnowledgePage>
     */
    /**
     * @param  array<int, float>  $vector
     * @param  list<string>|null  $scope
     */
    protected function pgsqlRankedQuery(array $vector, ?string $projectId = null, ?array $scope = null): Builder
    {
        /** @var string $encoded */
        $encoded = json_encode($vector) ?: '[]';

        return KnowledgePage::query()
            ->whereNotNull('embedding')
            ->when($projectId !== null, fn (Builder $query) => $query->where('project_id', $projectId))
            ->when($scope !== null, fn (Builder $query) => $query->whereIn('project_id', $scope))
            ->selectRaw('knowledge_pages.id, knowledge_pages.title, knowledge_pages.body, knowledge_pages.project_id, 1 - (embedding <=> ?::vector) as similarity', [$encoded])
            ->orderByRaw('embedding <=> ?::vector', [$encoded]);
    }

    /**
     * Exact cosine similarity between two sparse-or-dense float vectors.
     *
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    private function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        $count = min(count($a), count($b));
        for ($i = 0; $i < $count; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        if ($normA === 0.0 || $normB === 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }

    /**
     * Head excerpt of a page body, cut cleanly on a word boundary.
     */
    private function excerpt(string $body, int $length = 280): string
    {
        /** @var string $text */
        $text = preg_replace('/\s+/', ' ', trim($body)) ?? $body;

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        $cut = mb_substr($text, 0, $length);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space === false ? $cut : mb_substr($cut, 0, $space)).' …';
    }

    protected function currentUserCanUseWorkspace(): bool
    {
        $user = request()->user();

        return $user instanceof User && app(WorkspaceContext::class)->canUseWorkspace($user);
    }
}

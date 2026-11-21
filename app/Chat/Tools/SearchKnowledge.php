<?php

namespace App\Chat\Tools;

use App\Actions\Knowledge\SearchKnowledge as SearchKnowledgeAction;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Validation\Rule;

/**
 * Semantic search over the knowledge pages of the workspace the turn
 * runs in. The scope follows the requesting user exactly as the screens
 * show it: a guest's roster, a member's perimeter, transverse pages for
 * full members only. Without a configured embeddings endpoint the tool
 * answers as a structured result the model reacts to — the turn never
 * breaks.
 */
class SearchKnowledge extends ChatTool
{
    public function name(): string
    {
        return 'search_knowledge';
    }

    public function description(): string
    {
        return 'Semantic search over the knowledge pages of a workspace. Ask a natural-language question; results come ranked by similarity with title, excerpt and score. Use it to locate relevant pages before get_knowledge.';
    }

    public function schema(): array
    {
        return [
            'query' => [
                'type' => 'string',
                'description' => 'Natural-language question to search for',
                'required' => true,
            ],
            'project_id' => [
                'type' => 'string',
                'description' => 'Restrict results to pages attached to this project ULID',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Max results (1-50, default 10)',
            ],
        ];
    }

    protected function rules(Workspace $workspace): array
    {
        return [
            'query' => ['required', 'string', 'max:500'],
            'project_id' => [
                'nullable', 'ulid',
                Rule::exists('projects', 'id')->where('workspace_id', $workspace->id),
            ],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    protected function execute(array $validated, Workspace $workspace, User $user): array
    {
        return [
            'results' => SearchKnowledgeAction::run(
                $workspace,
                $validated['query'],
                $validated['project_id'] ?? null,
                $validated['limit'] ?? 10,
                $user,
            ),
        ];
    }
}

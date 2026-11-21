<?php

namespace App\Chat\Tools;

use App\Actions\Knowledge\GetKnowledgePage;
use App\Models\KnowledgePage;
use App\Models\User;
use App\Models\Workspace;

/**
 * Read one knowledge page in full (markdown body, parent, project, last
 * editor). The page resolves inside the turn's workspace; outside the
 * reader's perimeter it fails exactly like a missing one.
 */
class GetKnowledge extends ChatTool
{
    public function name(): string
    {
        return 'get_knowledge';
    }

    public function description(): string
    {
        return 'Get the full markdown content of a knowledge page: title, body, parent, project and last editor.';
    }

    public function schema(): array
    {
        return [
            'page_id' => [
                'type' => 'string',
                'description' => 'ULID of the knowledge page',
                'required' => true,
            ],
        ];
    }

    protected function rules(Workspace $workspace): array
    {
        return [
            'page_id' => ['required', 'ulid'],
        ];
    }

    protected function execute(array $validated, Workspace $workspace, User $user): array
    {
        return [
            'page' => GetKnowledgePage::run(
                KnowledgePage::query()->findOrFail($validated['page_id']),
                $user,
            ),
        ];
    }
}

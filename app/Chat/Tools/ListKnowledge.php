<?php

namespace App\Chat\Tools;

use App\Actions\Knowledge\ListKnowledgePages as ListKnowledgePagesAction;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Closure;

/**
 * List the knowledge pages of the workspace (id, title, parent and
 * project links) so the model rebuilds the tree and reads the pages it
 * needs with get_knowledge. The scope follows the requesting user the
 * same way the knowledge index does.
 */
class ListKnowledge extends ChatTool
{
    public function name(): string
    {
        return 'list_knowledge';
    }

    public function description(): string
    {
        return 'List the knowledge pages of a workspace: id, title, parent and project links. Rebuild the tree from parentId. Start here to get acquainted with a workspace, then call get_knowledge on the pages you need. Members only see the pages of their teams; transverse pages are reserved to full members.';
    }

    public function schema(): array
    {
        return [
            'project' => [
                'type' => 'string',
                'description' => 'Restrict the list to this project ULID, or the literal "transverse" for the workspace-level pages only',
            ],
        ];
    }

    protected function rules(Workspace $workspace): array
    {
        return [
            'project' => [
                'nullable', 'string', 'max:64',
                function (string $attribute, mixed $value, Closure $fail) use ($workspace): void {
                    if ($value === null || $value === 'transverse') {
                        return;
                    }

                    $exists = Project::query()
                        ->where('id', $value)
                        ->where('workspace_id', $workspace->id)
                        ->exists();

                    if (! $exists) {
                        $fail(__('validation.chat.project_unknown'));
                    }
                },
            ],
        ];
    }

    protected function execute(array $validated, Workspace $workspace, User $user): array
    {
        return [
            'pages' => ListKnowledgePagesAction::run($workspace, $user, $validated['project'] ?? null),
        ];
    }
}

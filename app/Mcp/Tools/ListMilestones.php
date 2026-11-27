<?php

namespace App\Mcp\Tools;

use App\Actions\Milestones\ListAccessibleMilestones;
use App\Mcp\Support\AgentTool;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Name('list_milestones')]
#[Description('List the milestones of a workspace, ordered by title. Each member only sees the milestones of the projects of their teams; the workspace owner sees them all. Pass project_id to list a single project\'s milestones (out-of-scope projects are not found); omit it to list the milestones of every project the caller may access.')]
class ListMilestones extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'project_id' => $schema->string()->description('ULID of the project; omit to list the milestones of every project the caller may access'),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $this->resolveWorkspace($request);

        $input = $request->validate([
            'project_id' => ['nullable', 'ulid'],
        ]);

        $project = isset($input['project_id'])
            ? Project::query()->findOrFail($input['project_id'])
            : null;

        return Response::structured([
            'milestones' => ListAccessibleMilestones::run($project, $this->currentUser($request)),
        ]);
    }
}

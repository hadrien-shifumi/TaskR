<?php

namespace App\Mcp\Tools;

use App\Actions\Projects\ArchiveProject as ArchiveProjectAction;
use App\Mcp\Support\AgentTool;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('archive_project')]
#[Description('Archive a project, or restore it if it is already archived.')]
class ArchiveProject extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'project_id' => $schema->string()->description('ULID of the project to archive or restore')->required(),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $this->resolveWorkspace($request);

        $project = Project::query()->findOrFail($request->get('project_id'));

        $project = ArchiveProjectAction::run($project);

        return Response::structured([
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'targetDate' => $project->target_date?->toISOString(),
                'archivedAt' => $project->archived_at?->toISOString(),
            ],
        ]);
    }
}

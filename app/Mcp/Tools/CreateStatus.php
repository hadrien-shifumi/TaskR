<?php

namespace App\Mcp\Tools;

use App\Actions\Statuses\CreateStatus as CreateStatusAction;
use App\Enums\WorkspaceRole;
use App\Mcp\Support\AgentTool;
use App\Mcp\Support\VisibleTo;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[VisibleTo(WorkspaceRole::Owner, WorkspaceRole::Member)]
#[Name('create_status')]
#[Description('Create a status in a workspace. Only the workspace owner may create statuses. Category must be one of: backlog, in_progress, done, cancelled.')]
class CreateStatus extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'name' => $schema->string()->description('Status name (max 255 chars, unique per workspace)')->required(),
            'category' => $schema->string()->enum(['backlog', 'in_progress', 'done', 'cancelled'])->description('Semantic category of the status')->required(),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $workspace = $this->resolveWorkspace($request);

        $user = $this->currentUser($request);
        if (! $workspace->hasOwner($user)) {
            throw new AuthorizationException('Only the workspace owner may create statuses.');
        }

        $action = app(CreateStatusAction::class);
        $data = $request->validate($action->rules());

        $status = $action->handle($workspace, $data);

        return Response::structured([
            'status' => [
                'id' => $status->id,
                'name' => $status->name,
                'category' => $status->category->value,
                'position' => $status->position,
            ],
        ]);
    }
}

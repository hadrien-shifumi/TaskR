<?php

namespace App\Mcp\Tools;

use App\Actions\Statuses\DeleteStatus as DeleteStatusAction;
use App\Enums\WorkspaceRole;
use App\Mcp\Support\AgentTool;
use App\Mcp\Support\VisibleTo;
use App\Models\Status;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[VisibleTo(WorkspaceRole::Owner, WorkspaceRole::Member)]
#[Name('delete_status')]
#[Description('Delete a status from a workspace. Only the workspace owner may delete statuses. Fails if the status is used by issues.')]
class DeleteStatus extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'status_id' => $schema->string()->description('ULID of the status to delete')->required(),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $workspace = $this->resolveWorkspace($request);

        $user = $this->currentUser($request);
        if (! $workspace->hasOwner($user)) {
            throw new AuthorizationException('Only the workspace owner may delete statuses.');
        }

        $status = Status::query()->findOrFail($request->get('status_id'));

        DeleteStatusAction::run($status);

        return Response::structured([
            'deleted' => true,
            'statusId' => $status->id,
        ]);
    }
}

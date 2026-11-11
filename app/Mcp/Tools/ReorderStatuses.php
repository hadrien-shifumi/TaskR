<?php

namespace App\Mcp\Tools;

use App\Actions\Statuses\ReorderStatuses as ReorderStatusesAction;
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
#[Name('reorder_statuses')]
#[Description('Reorder the statuses of a workspace. Only the workspace owner may reorder. Pass the status ids in the desired order.')]
class ReorderStatuses extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'ids' => $schema->array()->items($schema->string())->description('Status ULIDs in the desired order')->required(),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $workspace = $this->resolveWorkspace($request);

        $user = $this->currentUser($request);
        if (! $workspace->hasOwner($user)) {
            throw new AuthorizationException('Only the workspace owner may reorder statuses.');
        }

        $action = app(ReorderStatusesAction::class);
        $data = $request->validate($action->rules());

        $action->handle($workspace, $data);

        return Response::structured([
            'reordered' => true,
        ]);
    }
}

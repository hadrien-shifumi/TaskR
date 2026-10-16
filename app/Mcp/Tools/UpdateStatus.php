<?php

namespace App\Mcp\Tools;

use App\Actions\Statuses\UpdateStatus as UpdateStatusAction;
use App\Mcp\Support\AgentTool;
use App\Models\Status;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_status')]
#[Description('Update a status name or category. Only the workspace owner may update statuses.')]
class UpdateStatus extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'status_id' => $schema->string()->description('ULID of the status to update')->required(),
            'name' => $schema->string()->description('New status name (max 255 chars)')->required(),
            'category' => $schema->string()->enum(['backlog', 'in_progress', 'done', 'cancelled'])->description('New semantic category')->required(),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $workspace = $this->resolveWorkspace($request);

        $user = $this->currentUser($request);
        if (! $workspace->hasOwner($user)) {
            throw new AuthorizationException('Only the workspace owner may update statuses.');
        }

        $status = Status::query()->findOrFail($request->get('status_id'));

        $action = app(UpdateStatusAction::class);
        $data = $request->validate($action->rules());

        $status = $action->handle($status, $data);

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

<?php

namespace App\Mcp\Tools;

use App\Actions\Labels\CreateLabel as CreateLabelAction;
use App\Enums\WorkspaceRole;
use App\Mcp\Support\AgentTool;
use App\Mcp\Support\VisibleTo;
use App\Support\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[VisibleTo(WorkspaceRole::Owner, WorkspaceRole::Member)]
#[Name('create_label')]
#[Description('Create a label in a workspace. Name must be unique per workspace. Full members only: a guest gets forbidden.')]
class CreateLabel extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'name' => $schema->string()->description('Label name (max 100 chars, unique per workspace)')->required(),
            'color' => $schema->string()->description('Label color (max 50 chars, default gray)'),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $workspace = $this->resolveWorkspace($request);

        // Label management is reserved to full members: the web page
        // refuses guests, the tool mirrors it.
        if (! app(WorkspaceContext::class)->isFullMember($this->currentUser($request))) {
            throw new AuthorizationException('Only full members can manage labels.');
        }

        $action = app(CreateLabelAction::class);
        $data = $request->validate($action->rules());

        $label = $action->handle($workspace, $data);

        return Response::structured([
            'label' => [
                'id' => $label->id,
                'name' => $label->name,
                'color' => $label->color,
            ],
        ]);
    }
}

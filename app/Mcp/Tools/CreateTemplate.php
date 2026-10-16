<?php

namespace App\Mcp\Tools;

use App\Actions\Templates\CreateIssueTemplate as CreateIssueTemplateAction;
use App\Mcp\Support\AgentTool;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_template')]
#[Description('Create an issue template in a workspace. Only the workspace owner may create templates. Name must be unique per workspace.')]
class CreateTemplate extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'name' => $schema->string()->description('Template name (max 255 chars, unique per workspace)')->required(),
            'description' => $schema->string()->description('Optional template description (max 50000 chars)'),
            'priority' => $schema->string()->enum(['none', 'low', 'high', 'urgent'])->description('Default priority for issues created from this template'),
            'label_ids' => $schema->array()->items($schema->string())->description('ULIDs of workspace labels to apply'),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $workspace = $this->resolveWorkspace($request);

        $user = $this->currentUser($request);
        if (! $workspace->hasOwner($user)) {
            throw new AuthorizationException('Only the workspace owner may create templates.');
        }

        $action = app(CreateIssueTemplateAction::class);
        $data = $request->validate($action->rules());

        $template = $action->handle($workspace, $data);

        return Response::structured([
            'template' => [
                'id' => $template->id,
                'name' => $template->name,
                'description' => $template->description,
                'priority' => $template->priority?->value,
                'labelIds' => $template->labels->pluck('id')->values()->all(),
            ],
        ]);
    }
}

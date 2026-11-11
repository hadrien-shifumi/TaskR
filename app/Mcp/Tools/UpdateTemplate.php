<?php

namespace App\Mcp\Tools;

use App\Actions\Templates\UpdateIssueTemplate as UpdateIssueTemplateAction;
use App\Enums\WorkspaceRole;
use App\Mcp\Support\AgentTool;
use App\Mcp\Support\VisibleTo;
use App\Models\IssueTemplate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[VisibleTo(WorkspaceRole::Owner, WorkspaceRole::Member)]
#[Name('update_template')]
#[Description('Update an issue template. Only the workspace owner may update templates.')]
class UpdateTemplate extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'template_id' => $schema->string()->description('ULID of the template to update')->required(),
            'name' => $schema->string()->description('New template name (max 255 chars)')->required(),
            'description' => $schema->string()->description('New template description (max 50000 chars)'),
            'priority' => $schema->string()->enum(['none', 'low', 'high', 'urgent'])->description('New default priority'),
            'label_ids' => $schema->array()->items($schema->string())->description('ULIDs of workspace labels to apply'),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $workspace = $this->resolveWorkspace($request);

        $user = $this->currentUser($request);
        if (! $workspace->hasOwner($user)) {
            throw new AuthorizationException('Only the workspace owner may update templates.');
        }

        $template = IssueTemplate::query()->findOrFail($request->get('template_id'));

        $action = app(UpdateIssueTemplateAction::class);
        $data = $request->validate($action->rules());

        $template = $action->handle($template, $data);

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

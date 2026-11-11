<?php

namespace App\Mcp\Tools;

use App\Actions\Templates\DeleteIssueTemplate as DeleteIssueTemplateAction;
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
#[Name('delete_template')]
#[Description('Delete an issue template from a workspace. Only the workspace owner may delete templates.')]
class DeleteTemplate extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'template_id' => $schema->string()->description('ULID of the template to delete')->required(),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $workspace = $this->resolveWorkspace($request);

        $user = $this->currentUser($request);
        if (! $workspace->hasOwner($user)) {
            throw new AuthorizationException('Only the workspace owner may delete templates.');
        }

        $template = IssueTemplate::query()->findOrFail($request->get('template_id'));

        DeleteIssueTemplateAction::run($template);

        return Response::structured([
            'deleted' => true,
            'templateId' => $template->id,
        ]);
    }
}

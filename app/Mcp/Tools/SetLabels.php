<?php

namespace App\Mcp\Tools;

use App\Actions\Issues\SetLabels as SetLabelsAction;
use App\Mcp\Support\AgentTool;
use App\Models\Issue;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('set_labels')]
#[Description('Replace the labels of an issue. Label ids must belong to the workspace; pass an empty array to clear labels.')]
class SetLabels extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'issue_id' => $schema->string()->description('ULID of the issue')->required(),
            'label_ids' => $schema->array()->items($schema->string())->description('Label ULIDs (may be empty)')->required(),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $this->resolveWorkspace($request);

        $issue = Issue::query()->findOrFail($request->get('issue_id'));

        $action = app(SetLabelsAction::class);
        $labelIds = $request->validate($action->rules())['label_ids'];

        return Response::structured([
            'issue' => $this->presentIssue($action->handle($issue, $labelIds, $this->currentUser($request))),
        ]);
    }
}

<?php

namespace App\Mcp\Tools;

use App\Actions\TimeTracking\SetIssueEstimate as SetIssueEstimateAction;
use App\Mcp\Support\AgentTool;
use App\Models\Issue;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('set_issue_estimate')]
#[Description('Set the estimated hours for an issue. Pass null to clear the estimate.')]
class SetIssueEstimate extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'issue_id' => $schema->string()->description('ULID of the issue')->required(),
            'estimated_hours' => $schema->number()->description('Estimated hours (min 0, null to clear)'),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $this->resolveWorkspace($request);

        $issue = Issue::query()->findOrFail($request->get('issue_id'));

        $action = app(SetIssueEstimateAction::class);
        $estimatedHours = $request->validate($action->rules())['estimated_hours'] ?? null;
        $estimatedHours = $estimatedHours !== null ? (float) $estimatedHours : null;

        $issue = $action->handle($issue, $estimatedHours);

        return Response::structured([
            'issue' => $this->presentIssue($issue),
        ]);
    }
}

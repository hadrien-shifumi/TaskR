<?php

namespace App\Mcp\Tools;

use App\Actions\Milestones\SetIssueMilestone as SetIssueMilestoneAction;
use App\Mcp\Support\AgentTool;
use App\Models\Issue;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('set_issue_milestone')]
#[Description('Attach, move or clear the milestone of an issue. The milestone must belong to the issue\'s project. With cascade_children, the whole active sub-tree (descendants at every level) follows into the target milestone; archived descendants are never touched and detaching never cascades.')]
class SetIssueMilestone extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'issue_id' => $schema->string()->description('ULID of the issue to attach, move or detach')->required(),
            'milestone_id' => $schema->string()->nullable()->description('ULID of the milestone (must belong to the issue\'s project), or null to detach'),
            'cascade_children' => $schema->boolean()->description('Also attach every active descendant to the same milestone, at every level (default false). Descendants already in the target milestone are left untouched, archived descendants are never touched, and detaching never cascades.'),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $this->resolveWorkspace($request);

        $issue = Issue::query()->findOrFail($request->get('issue_id'));

        $action = app(SetIssueMilestoneAction::class);
        $validated = $request->validate($action->rules());

        return Response::structured([
            'issue' => $this->presentIssue($action->handle(
                $issue,
                $validated['milestone_id'] ?? null,
                $this->currentUser($request),
                (bool) ($validated['cascade_children'] ?? false),
            )),
        ]);
    }
}

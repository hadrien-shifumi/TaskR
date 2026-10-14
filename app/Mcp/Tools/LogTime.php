<?php

namespace App\Mcp\Tools;

use App\Actions\TimeTracking\CreateTimeEntry;
use App\Mcp\Support\AgentTool;
use App\Models\Issue;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('log_time')]
#[Description('Log worked hours on an issue. The authenticated user is the author of the entry.')]
class LogTime extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'issue_id' => $schema->string()->description('ULID of the issue')->required(),
            'hours' => $schema->number()->description('Worked hours (min 0.01)')->required(),
            'worked_at' => $schema->string()->description('Worked date (YYYY-MM-DD, default today)'),
            'note' => $schema->string()->description('Optional note (max 5000 chars)'),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $this->resolveWorkspace($request);

        $issue = Issue::query()->findOrFail($request->get('issue_id'));

        $action = app(CreateTimeEntry::class);
        $data = $request->validate($action->rules());

        $entry = $action->handle($issue, $this->currentUser($request), $data);

        return Response::structured([
            'timeEntry' => [
                'id' => $entry->id,
                'issueId' => $entry->issue_id,
                'authorId' => $entry->author_id,
                'workedAt' => $entry->worked_at->toDateString(),
                'hours' => (float) $entry->hours,
                'note' => $entry->note,
            ],
        ]);
    }
}

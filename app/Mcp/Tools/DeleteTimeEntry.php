<?php

namespace App\Mcp\Tools;

use App\Actions\TimeTracking\DeleteTimeEntry as DeleteTimeEntryAction;
use App\Mcp\Support\AgentTool;
use App\Models\Issue;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('delete_time_entry')]
#[Description('Delete a time entry. Only the author of the entry may delete it.')]
class DeleteTimeEntry extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'issue_id' => $schema->string()->description('ULID of the issue')->required(),
            'time_entry_id' => $schema->string()->description('ULID of the time entry to delete')->required(),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $this->resolveWorkspace($request);

        $issue = Issue::query()->findOrFail($request->get('issue_id'));
        $timeEntry = $issue->timeEntries()->findOrFail($request->get('time_entry_id'));

        $user = $this->currentUser($request);
        if ($timeEntry->author_id !== $user->id) {
            throw new AuthorizationException('You are not the author of this time entry.');
        }

        DeleteTimeEntryAction::run($timeEntry);

        return Response::structured([
            'deleted' => true,
            'timeEntryId' => $timeEntry->id,
        ]);
    }
}

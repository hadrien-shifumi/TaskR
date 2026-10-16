<?php

namespace App\Mcp\Tools;

use App\Actions\TimeTracking\UpdateTimeEntry as UpdateTimeEntryAction;
use App\Mcp\Support\AgentTool;
use App\Models\Issue;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_time_entry')]
#[Description('Update a time entry. Only the author of the entry may update it.')]
class UpdateTimeEntry extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'issue_id' => $schema->string()->description('ULID of the issue')->required(),
            'time_entry_id' => $schema->string()->description('ULID of the time entry to update')->required(),
            'hours' => $schema->number()->description('Worked hours (min 0.01)')->required(),
            'worked_at' => $schema->string()->description('Worked date (YYYY-MM-DD)'),
            'note' => $schema->string()->description('Optional note (max 5000 chars)'),
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

        $action = app(UpdateTimeEntryAction::class);
        $data = $request->validate($action->rules());

        $entry = $action->handle($timeEntry, $data);

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

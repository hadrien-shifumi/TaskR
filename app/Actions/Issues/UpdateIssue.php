<?php

namespace App\Actions\Issues;

use App\Actions\Audit\RecordActivity;
use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Enums\AuditEventType;
use App\Enums\Priority;
use App\Models\Issue;
use App\Models\User;
use App\Responders\Issues\IssueRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class UpdateIssue
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:50000'],
            'priority' => ['nullable', 'string', 'in:'.implode(',', array_column(Priority::cases(), 'value'))],
            'due_date' => ['nullable', 'date'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Issue $issue, array $data, ?User $user = null): Issue
    {
        // Out-of-scope issues fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessIssueAccessible($user, $issue);
        }

        $before = [
            'title' => $issue->title,
            'description' => $issue->description,
            'priority' => $issue->priority->value,
            'due_date' => $issue->due_date?->toDateString(),
        ];

        $issue->update([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'priority' => $data['priority'] ?? Priority::None->value,
            'due_date' => $data['due_date'] ?? null,
        ]);

        $this->recordUpdate($issue, $before, $user);

        return $issue->refresh();
    }

    /**
     * Journal the edit as one event per semantic change: title and
     * description share issue.updated, priority and due date get their
     * own event. A no-op update (same values) journals nothing.
     *
     * @param  array{title: string, description: ?string, priority: string, due_date: ?string}  $before
     */
    private function recordUpdate(Issue $issue, array $before, ?User $user): void
    {
        $changes = [];

        if ($issue->title !== $before['title']) {
            $changes['title'] = ['before' => $before['title'], 'after' => $issue->title];
        }

        if ($issue->description !== $before['description']) {
            $changes['description'] = ['before' => $before['description'], 'after' => $issue->description];
        }

        if ($changes !== []) {
            RecordActivity::run(AuditEventType::IssueUpdated, $issue, $user, $changes);
        }

        if ($issue->priority->value !== $before['priority']) {
            RecordActivity::run(AuditEventType::IssuePriorityChanged, $issue, $user, [
                'priority' => ['before' => $before['priority'], 'after' => $issue->priority->value],
            ]);
        }

        $afterDueDate = $issue->due_date?->toDateString();
        if ($afterDueDate !== $before['due_date']) {
            RecordActivity::run(AuditEventType::IssueDueDateChanged, $issue, $user, [
                'due_date' => ['before' => $before['due_date'], 'after' => $afterDueDate],
            ]);
        }
    }

    public function asController(ActionRequest $request, Issue $issue, IssueRedirectResponder $responder): RedirectResponse
    {
        $issue = $this->handle($issue, $request->validated(), $request->user());

        return $responder->updated($issue);
    }
}

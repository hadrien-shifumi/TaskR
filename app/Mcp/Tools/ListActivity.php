<?php

namespace App\Mcp\Tools;

use App\Enums\AuditEventType;
use App\Mcp\Support\AgentTool;
use App\Models\AuditEvent;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Presenters\Audit\AuditEventPresenter;
use App\Support\WorkspaceContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list_activity')]
#[Description('Read the audit journal (append-only activity log) of an issue or a project, newest first. Exactly one of issue_id / project_id is required. Each event carries type, actor, createdAt, commentId (for comment events) and changes (field diffs; flat added/removed lists for syncs). Comment bodies are not duplicated in the journal on creation or update — they live with the comment; only comment.deleted keeps a truncated snapshot. Guests and scoped members only see activity of objects in their perimeter; out-of-perimeter = not_found. Read-only: no event mutation tool exists.')]
class ListActivity extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'issue_id' => $schema->string()->description('ULID of the issue whose journal to read (exactly one of issue_id / project_id)'),
            'project_id' => $schema->string()->description('ULID of the project whose journal to read, its issues events included (exactly one of issue_id / project_id)'),
            'actor_id' => $schema->integer()->description('Filter by actor user id'),
            'type' => $schema->string()->description('Filter by event type (e.g. issue.created, comment.deleted)'),
            'limit' => $schema->integer()->description('Maximum events returned (1-100, default 50)'),
        ];
    }

    /**
     * Validation twin of schema(): every MCP parameter is declared both in
     * the JSON schema (agent-facing contract) and here (server-side rules).
     *
     * @return array<string, list<string>>
     */
    private function rules(): array
    {
        return [
            'workspace_id' => ['required', 'ulid'],
            'issue_id' => ['nullable', 'ulid'],
            'project_id' => ['nullable', 'ulid'],
            'actor_id' => ['nullable', 'integer', 'exists:users,id'],
            'type' => ['nullable', 'string', 'max:64'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $this->resolveWorkspace($request);
        $user = $this->currentUser($request);

        $input = $request->validate($this->rules());

        $issueId = $input['issue_id'] ?? null;
        $projectId = $input['project_id'] ?? null;

        $this->assertExactlyOneSubject($issueId, $projectId);

        $subject = $issueId !== null
            ? $this->resolveIssueSubject($user, $issueId)
            : $this->resolveProjectSubject($user, (string) $projectId);

        $eventType = null;
        if (($input['type'] ?? null) !== null) {
            $eventType = AuditEventType::tryFrom($input['type']);

            if ($eventType === null) {
                throw ValidationException::withMessages([
                    'type' => 'The selected type is invalid.',
                ]);
            }
        }

        $events = AuditEvent::query()
            ->when($issueId !== null, fn ($query) => $query->where('issue_id', $issueId))
            ->when($issueId === null, fn ($query) => $query->where('project_id', $projectId))
            ->with('actor')
            ->when($input['actor_id'] ?? null, fn ($query, int $actorId) => $query->where('actor_id', $actorId))
            ->when($eventType !== null, fn ($query) => $query->where('type', $eventType->value))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($input['limit'] ?? 50)
            ->get()
            ->map(AuditEventPresenter::detail(...))
            ->all();

        return Response::structured([
            'subject' => $subject,
            'events' => $events,
        ]);
    }

    /**
     * The journal is read per subject: exactly one of issue_id / project_id.
     */
    private function assertExactlyOneSubject(?string $issueId, ?string $projectId): void
    {
        if ($issueId !== null && $projectId !== null) {
            throw ValidationException::withMessages([
                'issue_id' => 'Provide either issue_id or project_id, not both.',
                'project_id' => 'Provide either issue_id or project_id, not both.',
            ]);
        }

        if ($issueId === null && $projectId === null) {
            throw ValidationException::withMessages([
                'issue_id' => 'Exactly one of issue_id or project_id is required.',
                'project_id' => 'Exactly one of issue_id or project_id is required.',
            ]);
        }
    }

    /**
     * @return array{type: string, id: string}
     */
    private function resolveIssueSubject(User $user, string $issueId): array
    {
        $issue = Issue::query()->findOrFail($issueId);
        app(WorkspaceContext::class)->abortUnlessIssueAccessible($user, $issue);

        return ['type' => 'issue', 'id' => $issue->id];
    }

    /**
     * @return array{type: string, id: string}
     */
    private function resolveProjectSubject(User $user, string $projectId): array
    {
        $project = Project::query()->findOrFail($projectId);
        app(WorkspaceContext::class)->abortUnlessProjectAccessible($user, $project);

        return ['type' => 'project', 'id' => $project->id];
    }
}

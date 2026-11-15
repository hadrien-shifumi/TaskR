<?php

namespace App\Mcp\Tools;

use App\Enums\AuditEventType;
use App\Mcp\Support\AgentTool;
use App\Models\AuditEvent;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Presenters\Audit\AuditEventPresenter;
use App\Support\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Name('list_activity')]
#[Description('Read the audit journal (append-only activity log) of an issue, a project, or the workspace itself, newest first. Provide issue_id, or project_id, or neither: with no subject the workspace-level journal is returned (membership and ownership events), reserved to full members. Each event carries type, actor, createdAt, commentId (for comment events) and changes (field diffs; flat added/removed lists for syncs). Comment bodies are not duplicated in the journal on creation or update — they live with the comment; only comment.deleted keeps a truncated snapshot. Each member only sees the activity of the objects of their project teams; anything outside the reader\'s teams is not_found. Read-only: no event mutation tool exists.')]
class ListActivity extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'issue_id' => $schema->string()->description('ULID of the issue whose journal to read (at most one of issue_id / project_id; neither = the workspace-level journal)'),
            'project_id' => $schema->string()->description('ULID of the project whose journal to read, its issues events included (at most one of issue_id / project_id; neither = the workspace-level journal)'),
            'actor_id' => $schema->integer()->description('Filter by actor user id'),
            'type' => $schema->string()->description('Filter by event type (e.g. issue.created, comment.deleted, membership.role_changed)'),
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
        $workspace = $this->resolveWorkspace($request);
        $user = $this->currentUser($request);

        $input = $request->validate($this->rules());

        $issueId = $input['issue_id'] ?? null;
        $projectId = $input['project_id'] ?? null;

        $this->assertAtMostOneSubject($issueId, $projectId);

        $subject = match (true) {
            $issueId !== null => $this->resolveIssueSubject($user, $issueId),
            $projectId !== null => $this->resolveProjectSubject($user, (string) $projectId),
            default => $this->resolveWorkspaceSubject($workspace, $user),
        };

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
            ->when($issueId === null && $projectId !== null, fn ($query) => $query->where('project_id', $projectId))
            ->when($issueId === null && $projectId === null, fn ($query) => $query
                ->whereNull('issue_id')
                ->whereNull('project_id')
                ->whereNull('knowledge_page_id')
                ->whereNull('milestone_id'))
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
     * The journal is read per subject: an issue, a project, or neither
     * for the workspace-level journal.
     */
    private function assertAtMostOneSubject(?string $issueId, ?string $projectId): void
    {
        if ($issueId !== null && $projectId !== null) {
            throw ValidationException::withMessages([
                'issue_id' => 'Provide either issue_id or project_id, not both.',
                'project_id' => 'Provide either issue_id or project_id, not both.',
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

    /**
     * The workspace-level journal (membership and ownership events) is
     * reserved to full members, like the members directory: a guest is
     * refused — they belong to the workspace, no existence is leaked.
     *
     * @return array{type: string, id: string}
     */
    private function resolveWorkspaceSubject(Workspace $workspace, User $user): array
    {
        if (! app(WorkspaceContext::class)->isFullMember($user)) {
            throw new AuthorizationException('Only full members can read the workspace journal.');
        }

        return ['type' => 'workspace', 'id' => $workspace->id];
    }
}

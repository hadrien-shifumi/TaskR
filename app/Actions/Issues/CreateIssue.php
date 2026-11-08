<?php

namespace App\Actions\Issues;

use App\Actions\Audit\RecordActivity;
use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Enums\AuditEventType;
use App\Enums\Priority;
use App\Models\Issue;
use App\Models\IssueTemplate;
use App\Models\Milestone;
use App\Models\User;
use App\Models\Workspace;
use App\Responders\Issues\IssueRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class CreateIssue
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
        $workspace = $this->currentWorkspace();

        return [
            'project_id' => ['required', 'ulid', Rule::exists('projects', 'id')->where('workspace_id', $workspace?->id)],
            'title' => ['required', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:50000'],
            'status_id' => ['nullable', 'ulid', Rule::exists('statuses', 'id')->where('workspace_id', $workspace?->id)],
            'priority' => ['nullable', 'string', 'in:'.implode(',', array_column(Priority::cases(), 'value'))],
            'due_date' => ['nullable', 'date'],
            'assignees' => ['nullable', 'array'],
            'assignees.*' => [Rule::exists('memberships', 'user_id')->where('workspace_id', $workspace?->id)],
            'label_ids' => ['nullable', 'array'],
            'label_ids.*' => ['ulid', Rule::exists('labels', 'id')->where('workspace_id', $workspace?->id)],
            'milestone_id' => ['nullable', 'ulid', Rule::exists('milestones', 'id')->where('workspace_id', $workspace?->id)],
            'template' => ['nullable', 'ulid'],
            'created_by' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Workspace $workspace, array $data, ?User $user = null): Issue
    {
        // A guest can only create within their perimeter. Without an
        // explicit user this is a trusted internal object run: HTTP and
        // MCP callers always pass the actor.
        if ($user !== null
            && app(WorkspaceContext::class)->accessibleProjectIds($user) !== null
            && ! in_array($data['project_id'], app(WorkspaceContext::class)->accessibleProjectIds($user), true)) {
            throw ValidationException::withMessages([
                'project_id' => [__('validation.issue.project_out_of_scope')],
            ]);
        }

        $data = $this->applyTemplateDefaults($workspace, $data);
        $this->assertMilestoneInProject($data);

        $issue = Issue::create([
            'project_id' => $data['project_id'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'status_id' => $data['status_id'] ?? $workspace->statuses()->firstOrFail()->id,
            'priority' => $data['priority'] ?? Priority::None->value,
            'due_date' => $data['due_date'] ?? null,
            'milestone_id' => $data['milestone_id'] ?? null,
            'created_by' => $data['created_by'] ?? null,
        ]);

        if (array_key_exists('assignees', $data) && is_array($data['assignees'])) {
            $issue->assignees()->sync($data['assignees']);
        }

        if (array_key_exists('label_ids', $data) && is_array($data['label_ids'])) {
            $issue->labels()->sync($data['label_ids']);
        }

        $issue = $issue->refresh();

        $this->recordCreation($issue, $user);

        return $issue;
    }

    /**
     * Journal the creation with the created fields (before null). Empty
     * optional fields (missing description, due date, labels or assignees)
     * stay out of the diff to keep the event light.
     */
    private function recordCreation(Issue $issue, ?User $user): void
    {
        $changes = [
            'title' => ['before' => null, 'after' => $issue->title],
        ];

        if ($issue->description !== null) {
            $changes['description'] = ['before' => null, 'after' => $issue->description];
        }

        $changes['status'] = ['before' => null, 'after' => $issue->status?->name];
        $changes['priority'] = ['before' => null, 'after' => $issue->priority->value];

        if ($issue->due_date !== null) {
            $changes['due_date'] = ['before' => null, 'after' => $issue->due_date->toDateString()];
        }

        if ($issue->milestone_id !== null) {
            $changes['milestone_id'] = ['before' => null, 'after' => $issue->milestone_id];
        }

        $labelIds = $issue->labels()->pluck('labels.id')->sort()->values()->all();
        if ($labelIds !== []) {
            $changes['labels'] = ['before' => null, 'after' => $labelIds];
        }

        $assigneeIds = $issue->assignees()->pluck('users.id')->sort()->values()->all();
        if ($assigneeIds !== []) {
            $changes['assignees'] = ['before' => null, 'after' => $assigneeIds];
        }

        RecordActivity::run(AuditEventType::IssueCreated, $issue, $user, $changes);
    }

    public function asController(ActionRequest $request, IssueRedirectResponder $responder): RedirectResponse
    {
        $data = $request->validated();
        $data['created_by'] ??= $request->user()?->getKey();

        $issue = $this->handle($this->currentWorkspace(), $data, $request->user());

        return $responder->created($issue);
    }

    /**
     * An issue is born attached to at most one milestone, and that
     * milestone must belong to the issue's own project — the same rule
     * the later attach action enforces. The workspace scoping already
     * happened in rules(): this is the project check.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function assertMilestoneInProject(array $data): void
    {
        if (empty($data['milestone_id'])) {
            return;
        }

        $milestone = (new Milestone)->withoutWorkspaceScope()->findOrFail($data['milestone_id']);

        if ($milestone->project_id !== $data['project_id']) {
            throw ValidationException::withMessages([
                'milestone_id' => [__('validation.milestone.issue_same_project')],
            ]);
        }
    }

    /**
     * Non-blocking template pre-fill: for description, priority and labels, an
     * absent key falls back to the template default; any explicitly provided
     * key (even empty) wins — so a client-side pre-fill makes this a no-op and
     * double application is impossible. The template never fills title,
     * status, due date, parent or assignees: those are contextual values.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyTemplateDefaults(Workspace $workspace, array $data): array
    {
        if (empty($data['template'])) {
            return $data;
        }

        /** @var IssueTemplate $template */
        $template = $workspace->issueTemplates()->findOrFail($data['template']);

        if (! array_key_exists('description', $data)) {
            $data['description'] = $template->description;
        }

        if (! array_key_exists('priority', $data)) {
            $data['priority'] = $template->priority?->value;
        }

        if (! array_key_exists('label_ids', $data)) {
            // Labels referenced by the template but since deleted are silently
            // ignored: the pivot join only returns labels that still exist.
            $data['label_ids'] = $template->labels()->pluck('labels.id')->all();
        }

        return $data;
    }
}

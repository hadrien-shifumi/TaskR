<?php

namespace App\Actions\Issues;

use App\Enums\Priority;
use App\Models\Issue;
use App\Models\User;
use App\Models\Workspace;
use App\Responders\Issues\IssueRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class CreateIssue
{
    use AsController;
    use AsObject;

    public function authorize(): bool
    {
        return $this->currentUserIsMember();
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
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Workspace $workspace, array $data): Issue
    {
        $issue = Issue::create([
            'project_id' => $data['project_id'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'status_id' => $data['status_id'] ?? $workspace->statuses()->firstOrFail()->id,
            'priority' => $data['priority'] ?? Priority::None->value,
            'due_date' => $data['due_date'] ?? null,
        ]);

        if (array_key_exists('assignees', $data) && is_array($data['assignees'])) {
            $issue->assignees()->sync($data['assignees']);
        }

        if (array_key_exists('label_ids', $data) && is_array($data['label_ids'])) {
            $issue->labels()->sync($data['label_ids']);
        }

        return $issue->refresh();
    }

    public function asController(ActionRequest $request, IssueRedirectResponder $responder): RedirectResponse
    {
        $issue = $this->handle($this->currentWorkspace(), $request->validated());

        return $responder->created($issue);
    }

    protected function currentUserIsMember(): bool
    {
        $user = request()->user();

        return $user instanceof User && app(WorkspaceContext::class)->isMember($user);
    }

    protected function currentWorkspace(): ?Workspace
    {
        return app(WorkspaceContext::class)->get();
    }
}

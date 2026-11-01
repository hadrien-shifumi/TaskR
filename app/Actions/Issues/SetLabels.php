<?php

namespace App\Actions\Issues;

use App\Actions\Audit\RecordActivity;
use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Enums\AuditEventType;
use App\Models\Issue;
use App\Models\User;
use App\Responders\Issues\IssueRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class SetLabels
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
            'label_ids' => ['present', 'array'],
            'label_ids.*' => [
                'ulid',
                Rule::exists('labels', 'id')->where('workspace_id', app(WorkspaceContext::class)->id()),
            ],
        ];
    }

    /**
     * Replace the full label set.
     *
     * @param  array<int, string>  $labelIds
     */
    public function handle(Issue $issue, array $labelIds, ?User $user = null): Issue
    {
        // Out-of-scope issues fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessIssueAccessible($user, $issue);
        }

        $before = $issue->labels()->pluck('labels.id')->sort()->values()->all();

        $issue->labels()->sync($labelIds);

        $after = $issue->labels()->pluck('labels.id')->sort()->values()->all();

        // A sync that changes nothing journals nothing.
        if ($after !== $before) {
            RecordActivity::run(AuditEventType::IssueUpdated, $issue, $user, [
                'labels' => ['before' => $before, 'after' => $after],
            ]);
        }

        return $issue->refresh();
    }

    public function asController(ActionRequest $request, Issue $issue, IssueRedirectResponder $responder): RedirectResponse
    {
        $issue = $this->handle($issue, $request->validated('label_ids'), $request->user());

        return $responder->updated($issue);
    }
}

<?php

namespace App\Actions\Issues;

use App\Models\Issue;
use App\Models\User;
use App\Responders\Issues\IssueRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class MoveIssue
{
    use AsController;
    use AsObject;

    public function authorize(): bool
    {
        $user = request()->user();

        return $user instanceof User && app(WorkspaceContext::class)->isMember($user);
    }

    public function rules(): array
    {
        return [
            'status_id' => [
                'required', 'ulid',
                Rule::exists('statuses', 'id')->where('workspace_id', app(WorkspaceContext::class)->id()),
            ],
        ];
    }

    /**
     * Any status of the workspace is reachable; no transition constraints.
     */
    public function handle(Issue $issue, string $statusId): Issue
    {
        $issue->update(['status_id' => $statusId]);

        return $issue->refresh();
    }

    public function asController(ActionRequest $request, Issue $issue, IssueRedirectResponder $responder): RedirectResponse
    {
        $issue = $this->handle($issue, $request->validated('status_id'));

        return $responder->updated($issue);
    }
}

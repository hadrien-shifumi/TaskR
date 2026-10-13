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

class SetAssignees
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
            'assignees' => ['present', 'array'],
            'assignees.*' => [
                Rule::exists('memberships', 'user_id')->where('workspace_id', app(WorkspaceContext::class)->id()),
            ],
        ];
    }

    /**
     * Replace the full assignee set; members only, any count.
     *
     * @param  array<int, string>  $userIds
     */
    public function handle(Issue $issue, array $userIds): Issue
    {
        $issue->assignees()->sync($userIds);

        return $issue->refresh();
    }

    public function asController(ActionRequest $request, Issue $issue, IssueRedirectResponder $responder): RedirectResponse
    {
        $issue = $this->handle($issue, $request->validated('assignees'));

        return $responder->updated($issue);
    }
}

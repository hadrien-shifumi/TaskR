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

class SetLabels
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
    public function handle(Issue $issue, array $labelIds): Issue
    {
        $issue->labels()->sync($labelIds);

        return $issue->refresh();
    }

    public function asController(ActionRequest $request, Issue $issue, IssueRedirectResponder $responder): RedirectResponse
    {
        $issue = $this->handle($issue, $request->validated('label_ids'));

        return $responder->updated($issue);
    }
}

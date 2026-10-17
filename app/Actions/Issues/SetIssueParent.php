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

class SetIssueParent
{
    use AsController;
    use AsObject;

    public function authorize(): bool
    {
        $user = request()->user();

        return $user instanceof User && app(WorkspaceContext::class)->canUseWorkspace($user);
    }

    public function rules(): array
    {
        return [
            'parent_id' => [
                'nullable', 'ulid',
                Rule::exists('issues', 'id')->where('workspace_id', app(WorkspaceContext::class)->id()),
            ],
        ];
    }

    /**
     * Soft hierarchy: any issue of the workspace can become the parent,
     * except one of the issue's own descendants (cycle).
     */
    public function handle(Issue $issue, ?string $parentId): Issue
    {
        if ($parentId !== null) {
            if ($parentId === $issue->id) {
                abort(422, 'An issue cannot be its own parent.');
            }

            if (in_array($issue->id, (new Issue)->withoutWorkspaceScope()->findOrFail($parentId)->ancestorIds(), true)) {
                abort(422, 'This parent would create a cycle in the hierarchy.');
            }
        }

        $issue->update(['parent_id' => $parentId]);

        return $issue->refresh();
    }

    public function asController(ActionRequest $request, Issue $issue, IssueRedirectResponder $responder): RedirectResponse
    {
        $issue = $this->handle($issue, $request->validated('parent_id'));

        return $responder->updated($issue);
    }
}

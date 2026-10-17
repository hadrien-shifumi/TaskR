<?php

namespace App\Actions\Issues;

use App\Models\Issue;
use App\Models\User;
use App\Responders\Issues\IssueRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
     * except one of the issue's own descendants (cycle). A guest may
     * only link within their perimeter.
     */
    public function handle(Issue $issue, ?string $parentId, ?User $user = null): Issue
    {
        // Out-of-scope issues fail exactly like non-existent ones.
        // Without a resolvable user this is a trusted internal object run.
        $user ??= request()->user();
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessIssueAccessible($user, $issue);
        }

        if ($parentId !== null) {
            if ($parentId === $issue->id) {
                abort(422, 'An issue cannot be its own parent.');
            }

            $parent = (new Issue)->withoutWorkspaceScope()->findOrFail($parentId);

            if ($user !== null
                && app(WorkspaceContext::class)->accessibleProjectIds($user) !== null
                && ! app(WorkspaceContext::class)->canAccessIssue($user, $parent)) {
                throw ValidationException::withMessages([
                    'parent_id' => [__('validation.issue.parent_out_of_scope')],
                ]);
            }

            if (in_array($issue->id, $parent->ancestorIds(), true)) {
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

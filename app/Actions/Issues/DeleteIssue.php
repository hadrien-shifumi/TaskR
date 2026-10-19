<?php

namespace App\Actions\Issues;

use App\Models\Issue;
use App\Models\User;
use App\Responders\Issues\IssueRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class DeleteIssue
{
    use AsController;
    use AsObject;

    public function authorize(): bool
    {
        $user = request()->user();

        return $user instanceof User && app(WorkspaceContext::class)->canUseWorkspace($user);
    }

    /**
     * Children become root issues (parent_id set to null by the FK).
     */
    public function handle(Issue $issue, ?User $user = null): Issue
    {
        // Out-of-scope issues fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessIssueAccessible($user, $issue);
        }

        $issue->delete();

        return $issue;
    }

    public function asController(Request $request, Issue $issue, IssueRedirectResponder $responder): RedirectResponse
    {
        $this->handle($issue, $request->user());

        return $responder->deleted();
    }
}

<?php

namespace App\Actions\Issues;

use App\Models\Issue;
use App\Models\User;
use App\Responders\Issues\IssueRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class DeleteIssue
{
    use AsController;
    use AsObject;

    public function authorize(): bool
    {
        $user = request()->user();

        return $user instanceof User && app(WorkspaceContext::class)->isMember($user);
    }

    /**
     * Children become root issues (parent_id set to null by the FK).
     */
    public function handle(Issue $issue): Issue
    {
        $issue->delete();

        return $issue;
    }

    public function asController(Issue $issue, IssueRedirectResponder $responder): RedirectResponse
    {
        $this->handle($issue);

        return $responder->deleted();
    }
}

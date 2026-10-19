<?php

namespace App\Actions\Projects;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Models\Project;
use App\Models\User;
use App\Responders\Projects\ProjectRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class DeleteProject
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    public function handle(Project $project, ?User $user = null): Project
    {
        // Out-of-scope projects fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user instanceof User) {
            app(WorkspaceContext::class)->abortUnlessProjectAccessible($user, $project);
        }

        $usage = $project->issuesCount();

        if ($usage > 0) {
            abort(409, "This project contains {$usage} issue(s). Archive it instead of deleting.");
        }

        $project->delete();

        return $project;
    }

    public function asController(Request $request, Project $project, ProjectRedirectResponder $responder): RedirectResponse
    {
        $this->handle($project, $request->user());

        return $responder->deleted();
    }
}

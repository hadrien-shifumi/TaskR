<?php

namespace App\Actions\Projects;

use App\Models\Project;
use App\Models\User;
use App\Responders\Projects\ProjectRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class DeleteProject
{
    use AsController;
    use AsObject;

    public function authorize(): bool
    {
        $user = request()->user();

        return $user instanceof User && app(WorkspaceContext::class)->isMember($user);
    }

    public function handle(Project $project): Project
    {
        $usage = $project->issuesCount();

        if ($usage > 0) {
            abort(409, "This project contains {$usage} issue(s). Archive it instead of deleting.");
        }

        $project->delete();

        return $project;
    }

    public function asController(Project $project, ProjectRedirectResponder $responder): RedirectResponse
    {
        $this->handle($project);

        return $responder->deleted();
    }
}

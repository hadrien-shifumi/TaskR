<?php

namespace App\Actions\Projects;

use App\Models\Project;
use App\Models\User;
use App\Responders\Projects\ProjectRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class ArchiveProject
{
    use AsController;
    use AsObject;

    public function authorize(): bool
    {
        $user = request()->user();

        return $user instanceof User && app(WorkspaceContext::class)->canUseWorkspace($user);
    }

    /**
     * Toggle archive state: archiving is always reversible.
     */
    public function handle(Project $project): Project
    {
        $project->update([
            'archived_at' => $project->isArchived() ? null : now(),
        ]);

        return $project->refresh();
    }

    public function asController(Project $project, ProjectRedirectResponder $responder): RedirectResponse
    {
        $project = $this->handle($project);

        return $project->isArchived()
            ? $responder->archived($project)
            : $responder->unarchived($project);
    }
}

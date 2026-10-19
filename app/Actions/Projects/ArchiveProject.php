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
    public function handle(Project $project, ?User $user = null): Project
    {
        // Out-of-scope projects fail exactly like non-existent ones; a
        // project of the perimeter stays archivable by its guests.
        $user ??= request()->user();
        if ($user instanceof User) {
            app(WorkspaceContext::class)->abortUnlessProjectAccessible($user, $project);
        }

        $project->update([
            'archived_at' => $project->isArchived() ? null : now(),
        ]);

        return $project->refresh();
    }

    public function asController(Project $project, ProjectRedirectResponder $responder): RedirectResponse
    {
        $project = $this->handle($project, request()->user());

        return $project->isArchived()
            ? $responder->archived($project)
            : $responder->unarchived($project);
    }
}

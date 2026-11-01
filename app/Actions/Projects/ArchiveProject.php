<?php

namespace App\Actions\Projects;

use App\Actions\Audit\RecordActivity;
use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Enums\AuditEventType;
use App\Models\Project;
use App\Models\User;
use App\Responders\Projects\ProjectRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class ArchiveProject
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    /**
     * Toggle archive state: archiving is always reversible.
     */
    public function handle(Project $project, ?User $user = null): Project
    {
        // Out-of-scope projects fail exactly like non-existent ones; a
        // project of the perimeter stays archivable by its guests.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user instanceof User) {
            app(WorkspaceContext::class)->abortUnlessProjectAccessible($user, $project);
        }

        $wasArchived = $project->isArchived();

        $project->update([
            'archived_at' => $wasArchived ? null : now(),
        ]);

        RecordActivity::run(
            $wasArchived ? AuditEventType::ProjectUnarchived : AuditEventType::ProjectArchived,
            $project,
            $user,
        );

        return $project->refresh();
    }

    public function asController(Request $request, Project $project, ProjectRedirectResponder $responder): RedirectResponse
    {
        $project = $this->handle($project, $request->user());

        return $project->isArchived()
            ? $responder->archived($project)
            : $responder->unarchived($project);
    }
}

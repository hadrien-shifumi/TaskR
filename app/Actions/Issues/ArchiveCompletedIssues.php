<?php

namespace App\Actions\Issues;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Models\Project;
use App\Models\User;
use App\Responders\Projects\ProjectRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class ArchiveCompletedIssues
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    /**
     * Archive every unarchived done/cancelled issue of a project in one
     * transaction. Returns the number of archived issues for the flash
     * message. Owner-only, mirroring the unit archive toggle.
     */
    public function handle(Project $project, ?User $user = null): int
    {
        // Out-of-scope projects fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessProjectAccessible($user, $project);

            $workspace = app(WorkspaceContext::class)->getOrFail();
            abort_unless($workspace->hasOwner($user), 403, 'Only the workspace owner may archive or unarchive issues.');
        }

        $issues = $project->issues()
            ->unarchived()
            ->whereHas('status', fn ($status) => $status->whereIn('category', ['done', 'cancelled']))
            ->get();

        // Each eligible issue goes through the unit toggle so the hierarchy
        // cascade applies (children of an archived parent follow it).
        return DB::transaction(function () use ($issues): int {
            $archived = 0;

            foreach ($issues as $issue) {
                ArchiveIssue::run($issue);
                $archived++;
            }

            return $archived;
        });
    }

    public function asController(ActionRequest $request, Project $project, ProjectRedirectResponder $responder): RedirectResponse
    {
        $count = $this->handle($project, $request->user());

        return $responder->completedArchived($project, $count);
    }
}

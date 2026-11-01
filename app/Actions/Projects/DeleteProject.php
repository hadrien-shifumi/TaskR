<?php

namespace App\Actions\Projects;

use App\Actions\Audit\RecordActivity;
use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Enums\AuditEventType;
use App\Models\KnowledgePage;
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

        // Count the pages before the cascade: after the move they are
        // detached from the project.
        $trashedPages = KnowledgePage::where('project_id', $project->id)->count();

        // Move all knowledge pages attached to this project to trash
        // BEFORE deleting the project. This preserves the documentation
        // (recoverable from trash for 30 days).
        $this->moveKnowledgePagesToTrash($project, $user);

        // The journal is written right before the destructive write: the
        // subject FK requires the project to still exist, and the DB then
        // nulls it on delete. The event stays readable through its
        // workspace and this loss inventory. The issue count is zero by
        // construction (409 guard above), so only the trashed pages are
        // inventoried.
        RecordActivity::run(AuditEventType::ProjectDeleted, $project, $user, [
            'name' => $project->name,
            'description' => $project->description,
            'target_date' => $project->target_date?->toDateString(),
            'knowledge_pages_trashed' => $trashedPages,
        ]);

        $project->delete();

        return $project;
    }

    /**
     * Soft delete all knowledge pages attached to a project.
     *
     * Pages are trashed with reason 'cascade_project' and deleted_by_id
     * set to the user who deleted the project (or null for internal runs).
     *
     * The whole subtree is trashed at once (unlike DeleteKnowledgePage
     * which blocks pages with children) because we trash everything.
     *
     * After trashing, project_id is nulled on all pages (trashed or not)
     * so the FK cascade on projects does not hard-delete the trashed rows.
     */
    private function moveKnowledgePagesToTrash(Project $project, ?User $user): void
    {
        // Soft-delete all non-trashed pages attached to this project.
        // The SoftDeletes global scope excludes already-trashed pages.
        KnowledgePage::where('project_id', $project->id)
            ->cursor()
            ->each(function (KnowledgePage $page) use ($user): void {
                $page->forceFill([
                    'deleted_by_id' => $user?->id,
                    'deleted_reason' => 'cascade_project',
                ])->save();
                $page->delete();
            });

        // Detach all pages (trashed and non-trashed) from the project so
        // the FK cascadeOnDelete does not hard-delete the trashed rows
        // when the project row is removed. The pages become transverse
        // (project_id = null) and are recoverable from the trash.
        KnowledgePage::withTrashed()
            ->where('project_id', $project->id)
            ->update(['project_id' => null]);
    }

    public function asController(Request $request, Project $project, ProjectRedirectResponder $responder): RedirectResponse
    {
        $this->handle($project, $request->user());

        return $responder->deleted();
    }
}

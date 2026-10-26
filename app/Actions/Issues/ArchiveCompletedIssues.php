<?php

namespace App\Actions\Issues;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Enums\StatusCategory;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Responders\Projects\ProjectRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ArchiveCompletedIssues
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserIsOwner();
    }

    /**
     * Archive every closable root issue of the project through the unitary
     * toggle (cascade included): done/cancelled roots with a closed
     * subtree. Subtrees still holding open work are skipped, never forced.
     */
    public function handle(Project $project, ?User $user = null): int
    {
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessProjectAccessible($user, $project);
        }

        return DB::transaction(function () use ($project, $user): int {
            $archived = 0;

            $this->closableRoots($project)->each(function (Issue $issue) use ($user, &$archived): void {
                try {
                    ArchiveIssue::run($issue, $user);
                    $archived += 1;
                } catch (HttpException $exception) {
                    // An open descendant blocks this subtree (409): leave it
                    // in place. Anything else is a real failure.
                    if ($exception->getStatusCode() !== 409) {
                        throw $exception;
                    }
                }
            });

            return $archived;
        });
    }

    public function asController(Request $request, Project $project, ProjectRedirectResponder $responder): RedirectResponse
    {
        $count = $this->handle($project, $request->user());

        return $responder->issuesArchived($count);
    }

    /**
     * Root issues (no parent) of the project, not archived yet, in a
     * closed category — their children follow through the cascade.
     *
     * @return Collection<int, Issue>
     */
    private function closableRoots(Project $project)
    {
        return $project->issues()
            ->whereNull('archived_at')
            ->whereNull('parent_id')
            ->whereHas('status', fn ($query) => $query->whereIn('category', [
                StatusCategory::Done->value,
                StatusCategory::Cancelled->value,
            ]))
            ->get();
    }

    protected function currentUserIsOwner(): bool
    {
        $user = request()->user();
        $workspace = $this->currentWorkspace();

        return $user instanceof User && $workspace !== null && $workspace->hasOwner($user);
    }
}

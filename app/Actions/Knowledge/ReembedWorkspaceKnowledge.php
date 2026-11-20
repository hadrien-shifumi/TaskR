<?php

namespace App\Actions\Knowledge;

use App\Jobs\VectorizeKnowledgePage;
use App\Models\KnowledgePage;
use App\Models\User;
use App\Models\Workspace;
use App\Responders\Knowledge\EmbeddingsSettingsRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Re-run the vectorization of every page of the current workspace, one
 * queued job per page (same jobs, retries and backoff as the automatic
 * vectorization). Meant to run after the owner changed the provider or
 * the model: asynchronous by design, never a synchronous sweep — a
 * large base would block the request.
 */
class ReembedWorkspaceKnowledge
{
    use AsController;
    use AsObject;

    public function authorize(): bool
    {
        $user = request()->user();
        $workspace = app(WorkspaceContext::class)->get();

        return $user instanceof User
            && $workspace instanceof Workspace
            && $workspace->hasOwner($user);
    }

    /**
     * Queue one vectorization job per page of the workspace and return
     * the number of queued jobs.
     */
    public function handle(Workspace $workspace): int
    {
        $pages = KnowledgePage::query()->orderBy('id')->get();

        $pages->each(fn (KnowledgePage $page) => VectorizeKnowledgePage::dispatch($page));

        return $pages->count();
    }

    public function asController(ActionRequest $request, EmbeddingsSettingsRedirectResponder $responder): RedirectResponse
    {
        $count = $this->handle(app(WorkspaceContext::class)->getOrFail());

        return $responder->reembed($count);
    }
}

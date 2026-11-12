<?php

namespace App\Actions\Milestones;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class SearchMilestones
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'max:500'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:25'],
        ];
    }

    /**
     * The milestones of a project matching a free-text search (title),
     * within the project perimeter — the live option feed of the
     * set-parent dialog.
     *
     * @param  non-empty-string  $q
     * @return list<array{id: string, title: string}>
     */
    public function handle(Project $project, string $q, int $limit = 10, ?User $user = null): array
    {
        // Out-of-scope projects fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessProjectAccessible($user, $project);
        }

        $search = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);

        return $project->milestones()
            ->where('title', 'like', '%'.$search.'%')
            ->orderBy('title')
            ->limit($limit)
            ->get(['id', 'title'])
            ->map(fn (Milestone $milestone): array => [
                'id' => $milestone->id,
                'title' => $milestone->title,
            ])
            ->values()
            ->all();
    }

    public function asController(ActionRequest $request, Project $project): JsonResponse
    {
        $data = $request->validated();

        return response()->json($this->handle(
            $project,
            (string) $data['q'],
            (int) ($data['limit'] ?? 10),
            $request->user(),
        ));
    }
}

<?php

namespace App\Actions\Knowledge;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Models\KnowledgePage;
use App\Models\User;
use App\Models\Workspace;
use App\Presenters\Projects\ProjectPresenter;
use App\Responders\Knowledge\KnowledgePageResponder;
use App\Support\WorkspaceContext;
use Illuminate\Support\Fluent;
use Illuminate\Validation\Rule;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

class ShowKnowledgePage
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    /**
     * The sidebar project filter must be the `transverse` sentinel or a
     * ULID of a project that belongs to the current workspace; anything
     * else is a validation error, not a silently empty list.
     */
    public function rules(): array
    {
        return [
            'project' => [
                'nullable', 'string',
                Rule::when(
                    fn (Fluent $data): bool => $data->get('project') !== 'transverse',
                    [
                        'ulid',
                        Rule::exists('projects', 'id')->where(
                            'workspace_id',
                            app(WorkspaceContext::class)->id(),
                        ),
                    ],
                ),
            ],
        ];
    }

    /**
     * @return array{pages: array<int, array<string, mixed>>, projects: array<int, array<string, mixed>>, selected: array<string, mixed>|null, projectFilter: string|null}
     */
    public function handle(Workspace $workspace, ?KnowledgePage $selected = null, ?User $user = null, ?string $projectFilter = null): array
    {
        $scope = $user instanceof User
            ? app(WorkspaceContext::class)->accessibleProjectIds($user)
            : null;

        // Out-of-scope detail pages fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($selected !== null && $user !== null) {
            app(WorkspaceContext::class)->abortUnlessKnowledgePageAccessible($user, $selected);
        }

        return [
            'pages' => ListKnowledgePages::run($workspace, $user, $projectFilter),
            'projects' => $workspace->projects()
                ->when($scope !== null, fn ($query) => $query->whereIn('id', $scope))
                ->orderBy('name')
                ->get()
                ->map(fn ($project) => ProjectPresenter::option($project))
                ->all(),
            'selected' => $selected !== null ? GetKnowledgePage::run($selected, $user) : null,
            'projectFilter' => $projectFilter,
        ];
    }

    public function asController(ActionRequest $request, KnowledgePageResponder $responder, ?KnowledgePage $page = null): Response
    {
        $projectFilter = $request->validated('project');
        $projectFilter = is_string($projectFilter) && $projectFilter !== '' ? $projectFilter : null;

        $data = $this->handle(
            app(WorkspaceContext::class)->getOrFail(),
            $page,
            $request->user(),
            $projectFilter,
        );

        return $responder->toResponse($request, $data);
    }
}

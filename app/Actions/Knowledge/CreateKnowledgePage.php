<?php

namespace App\Actions\Knowledge;

use App\Jobs\VectorizeKnowledgePage;
use App\Models\KnowledgePage;
use App\Models\User;
use App\Models\Workspace;
use App\Responders\Knowledge\KnowledgeRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class CreateKnowledgePage
{
    use AsController;
    use AsObject;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:500'],
            'body' => ['required', 'string'],
            'project_id' => [
                'nullable', 'ulid',
                Rule::exists('projects', 'id')->where('workspace_id', app(WorkspaceContext::class)->id()),
            ],
            'parent_id' => [
                'nullable', 'ulid',
                Rule::exists('knowledge_pages', 'id')->where('workspace_id', app(WorkspaceContext::class)->id()),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Workspace $workspace, User $editor, array $data): KnowledgePage
    {
        // A guest works strictly within their perimeter: the page keeps a
        // project of the perimeter and an accessible parent.
        $scope = app(WorkspaceContext::class)->accessibleProjectIds($editor);
        if ($scope !== null) {
            $projectId = $data['project_id'] ?? null;
            if ($projectId === null || ! in_array($projectId, $scope, true)) {
                throw ValidationException::withMessages([
                    'project_id' => [__('validation.knowledge.project_out_of_scope')],
                ]);
            }

            $parentId = $data['parent_id'] ?? null;
            if ($parentId !== null) {
                $parent = (new KnowledgePage)->withoutWorkspaceScope()->find($parentId);
                if ($parent === null || ! app(WorkspaceContext::class)->canAccessKnowledgePage($editor, $parent)) {
                    throw ValidationException::withMessages([
                        'parent_id' => [__('validation.knowledge.parent_out_of_scope')],
                    ]);
                }
            }
        }

        $page = KnowledgePage::create([
            'project_id' => $data['project_id'] ?? null,
            'parent_id' => $data['parent_id'] ?? null,
            'title' => $data['title'],
            'body' => $data['body'],
            'last_editor_id' => $editor->id,
        ]);

        VectorizeKnowledgePage::dispatch($page);

        return $page->refresh();
    }

    public function asController(ActionRequest $request, KnowledgeRedirectResponder $responder): RedirectResponse
    {
        $page = $this->handle(
            app(WorkspaceContext::class)->getOrFail(),
            $request->user(),
            $request->validated(),
        );

        return $responder->created($page);
    }

    protected function currentUserCanUseWorkspace(): bool
    {
        $user = request()->user();

        return $user instanceof User && app(WorkspaceContext::class)->canUseWorkspace($user);
    }
}

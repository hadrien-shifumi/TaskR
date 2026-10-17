<?php

namespace App\Actions\Knowledge;

use App\Jobs\VectorizeKnowledgePage;
use App\Models\KnowledgePage;
use App\Models\User;
use App\Responders\Knowledge\KnowledgeRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class UpdateKnowledgePage
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
     * Same hierarchy invariants as SetIssueParent: parent of the same
     * workspace (scoped rule), no self-parenting, no cycle.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(KnowledgePage $page, User $editor, array $data): KnowledgePage
    {
        $parentId = $data['parent_id'] ?? null;

        if ($parentId !== null) {
            if ($parentId === $page->id) {
                abort(422, 'A page cannot be its own parent.');
            }

            if (in_array($page->id, (new KnowledgePage)->withoutWorkspaceScope()->findOrFail($parentId)->ancestorIds(), true)) {
                abort(422, 'This parent would create a cycle in the hierarchy.');
            }
        }

        $page->update([
            'project_id' => $data['project_id'] ?? null,
            'parent_id' => $parentId,
            'title' => $data['title'],
            'body' => $data['body'],
            'last_editor_id' => $editor->id,
        ]);

        VectorizeKnowledgePage::dispatch($page);

        return $page->refresh();
    }

    public function asController(ActionRequest $request, KnowledgePage $page, KnowledgeRedirectResponder $responder): RedirectResponse
    {
        $page = $this->handle($page, $request->user(), $request->validated());

        return $responder->updated($page);
    }

    protected function currentUserCanUseWorkspace(): bool
    {
        $user = request()->user();

        return $user instanceof User && app(WorkspaceContext::class)->canUseWorkspace($user);
    }
}

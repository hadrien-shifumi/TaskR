<?php

namespace App\Actions\Knowledge;

use App\Actions\Audit\RecordActivity;
use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Enums\AuditEventType;
use App\Jobs\VectorizeKnowledgePage;
use App\Models\KnowledgePage;
use App\Models\KnowledgePageVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Responders\Knowledge\KnowledgeRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class CreateKnowledgePage
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
        // A scoped visitor works strictly within their perimeter's project
        // pages; a scoped member — a full member — may also write
        // transverse pages, a guest may not.
        $context = app(WorkspaceContext::class);
        $scope = $context->accessibleProjectIds($editor);
        if ($scope !== null) {
            $projectId = $data['project_id'] ?? null;
            $seesTransverse = $context->seesTransverseKnowledgePages($editor);

            if (($projectId === null && ! $seesTransverse) || ($projectId !== null && ! in_array($projectId, $scope, true))) {
                throw ValidationException::withMessages([
                    'project_id' => [__('validation.knowledge.project_out_of_scope')],
                ]);
            }

            $parentId = $data['parent_id'] ?? null;
            if ($parentId !== null) {
                $parent = (new KnowledgePage)->withoutWorkspaceScope()->find($parentId);
                if ($parent === null || ! $context->canAccessKnowledgePage($editor, $parent)) {
                    throw ValidationException::withMessages([
                        'parent_id' => [__('validation.knowledge.parent_out_of_scope')],
                    ]);
                }
            }
        }

        $page = DB::transaction(function () use ($data, $editor): KnowledgePage {
            $page = KnowledgePage::create([
                'project_id' => $data['project_id'] ?? null,
                'parent_id' => $data['parent_id'] ?? null,
                'title' => $data['title'],
                'body' => $data['body'],
                'last_editor_id' => $editor->id,
            ]);

            // Create the initial version (v1) atomically with the page.
            KnowledgePageVersion::create([
                'page_id' => $page->id,
                'title' => $page->title,
                'body' => $page->body,
                'editor_id' => $editor->id,
                'created_at' => $page->created_at,
            ]);

            // Journal the creation in the same transaction. The full body
            // lives in the version table: the journal keeps a ~2000
            // characters excerpt only.
            RecordActivity::run(AuditEventType::KnowledgePageCreated, $page, $editor, [
                'title' => ['before' => null, 'after' => $page->title],
                'body' => ['before' => null, 'after' => Str::limit($page->body, 2000)],
            ]);

            return $page;
        });

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
}

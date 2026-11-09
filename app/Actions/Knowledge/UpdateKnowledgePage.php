<?php

namespace App\Actions\Knowledge;

use App\Actions\Audit\RecordActivity;
use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Enums\AuditEventType;
use App\Jobs\VectorizeKnowledgePage;
use App\Models\KnowledgePage;
use App\Models\KnowledgePageVersion;
use App\Models\User;
use App\Responders\Knowledge\KnowledgeRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class UpdateKnowledgePage
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
            // Optimistic locking: the page's updated_at as seen when the editor opened it.
            'base_updated_at' => ['nullable', 'date'],
            // Explicitly overwrite a page modified since base_updated_at.
            'force_overwrite' => ['nullable', 'boolean'],
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
        // Out-of-scope pages fail exactly like non-existent ones.
        app(WorkspaceContext::class)->abortUnlessKnowledgePageAccessible($editor, $page);

        // Optimistic locking: the editor sends the page's updated_at as it
        // was when the editor was opened. A newer updated_at means someone
        // else saved in between: refuse with a 409 unless forced.
        $baseUpdatedAt = $data['base_updated_at'] ?? null;
        $forceOverwrite = $data['force_overwrite'] ?? false;

        if ($baseUpdatedAt !== null && ! $forceOverwrite) {
            $page->refresh();
            $currentUpdatedAt = $page->updated_at;

            if ($currentUpdatedAt->greaterThan(Carbon::parse($baseUpdatedAt))) {
                $lastEditor = $page->lastEditor;

                throw new HttpResponseException(
                    response()->json([
                        'message' => __('validation.knowledge.conflict_detected', [
                            'editor' => $lastEditor?->name ?? 'unknown',
                        ]),
                        'conflict' => [
                            'lastEditor' => $lastEditor?->name,
                            'lastEditorId' => $lastEditor?->id,
                            'updatedAt' => $currentUpdatedAt->toISOString(),
                        ],
                    ], 409)
                );
            }
        }

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

        $parentId = $data['parent_id'] ?? null;

        if ($parentId !== null) {
            if ($parentId === $page->id) {
                abort(422, 'A page cannot be its own parent.');
            }

            if (in_array($page->id, (new KnowledgePage)->withoutWorkspaceScope()->findOrFail($parentId)->ancestorIds(), true)) {
                abort(422, 'This parent would create a cycle in the hierarchy.');
            }
        }

        $before = [
            'title' => $page->title,
            'body' => $page->body,
            'project_id' => $page->project_id,
            'parent_id' => $page->parent_id,
        ];

        $page = DB::transaction(function () use ($page, $data, $parentId, $editor, $before): KnowledgePage {
            $page->update([
                'project_id' => $data['project_id'] ?? null,
                'parent_id' => $parentId,
                'title' => $data['title'],
                'body' => $data['body'],
                'last_editor_id' => $editor->id,
            ]);

            // Create a new version capturing the updated state.
            KnowledgePageVersion::create([
                'page_id' => $page->id,
                'title' => $page->title,
                'body' => $page->body,
                'editor_id' => $editor->id,
                'created_at' => $page->updated_at,
            ]);

            $this->recordUpdate($page, $before, $editor);

            return $page;
        });

        VectorizeKnowledgePage::dispatch($page);

        return $page->refresh();
    }

    /**
     * Journal the edit in the same transaction. The full history lives in
     * the version table: bodies are truncated to ~2000 characters. A
     * no-op update (same values) journals nothing.
     *
     * @param  array{title: string, body: string, project_id: ?string, parent_id: ?string}  $before
     */
    private function recordUpdate(KnowledgePage $page, array $before, User $editor): void
    {
        $changes = [];

        if ($page->title !== $before['title']) {
            $changes['title'] = ['before' => $before['title'], 'after' => $page->title];
        }

        if ($page->body !== $before['body']) {
            $changes['body'] = [
                'before' => Str::limit($before['body'], 2000),
                'after' => Str::limit($page->body, 2000),
            ];
        }

        if ($page->project_id !== $before['project_id']) {
            $changes['project_id'] = ['before' => $before['project_id'], 'after' => $page->project_id];
        }

        if ($page->parent_id !== $before['parent_id']) {
            $changes['parent_id'] = ['before' => $before['parent_id'], 'after' => $page->parent_id];
        }

        if ($changes !== []) {
            RecordActivity::run(AuditEventType::KnowledgePageUpdated, $page, $editor, $changes);
        }
    }

    public function asController(ActionRequest $request, KnowledgePage $page, KnowledgeRedirectResponder $responder): RedirectResponse
    {
        $page = $this->handle($page, $request->user(), $request->validated());

        return $responder->updated($page);
    }
}

<?php

namespace App\Actions\Knowledge;

use App\Actions\Audit\RecordActivity;
use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Enums\AuditEventType;
use App\Models\KnowledgePage;
use App\Models\Project;
use App\Models\User;
use App\Responders\Knowledge\KnowledgeRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class MoveKnowledgePage
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
            'parent_id' => [
                'nullable', 'ulid',
                Rule::exists('knowledge_pages', 'id')->where(
                    'workspace_id',
                    app(WorkspaceContext::class)->id(),
                ),
            ],
        ];
    }

    /**
     * Dedicated move endpoint for the sidebar drag & drop: re-parent only.
     *
     * The page's project never changes — the new parent must live in the
     * same project (or both stay transverse) — and the hierarchy
     * invariants of UpdateKnowledgePage apply: no self-parent, no cycle.
     * A move touches no content, so no version is created; the re-parent
     * is journalled on the page's activity.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(KnowledgePage $page, User $user, array $data): KnowledgePage
    {
        // Out-of-scope pages fail exactly like non-existent ones.
        app(WorkspaceContext::class)->abortUnlessKnowledgePageAccessible($user, $page);

        $context = app(WorkspaceContext::class);
        $scope = $context->accessibleProjectIds($user);

        if ($scope !== null) {
            $parentId = $data['parent_id'] ?? null;
            if ($parentId !== null) {
                $parent = (new KnowledgePage)->withoutWorkspaceScope()->find($parentId);
                if ($parent === null || ! $context->canAccessKnowledgePage($user, $parent)) {
                    throw ValidationException::withMessages([
                        'parent_id' => [__('validation.knowledge.parent_out_of_scope')],
                    ]);
                }
            }

            // Project pages are contributor territory: a roster Guest
            // reads them fully but never writes them — moving one is a
            // write (D6/D7).
            if ($page->project_id !== null && ! Project::query()->findOrFail($page->project_id)->isContributor($user)) {
                throw ValidationException::withMessages([
                    'parent_id' => [__('validation.knowledge.project_guest_read_only')],
                ]);
            }
        }

        $parentId = $data['parent_id'] ?? null;

        if ($parentId !== null) {
            if ($parentId === $page->id) {
                abort(422, 'A page cannot be its own parent.');
            }

            $parent = (new KnowledgePage)->withoutWorkspaceScope()->findOrFail($parentId);

            // A drag re-parents inside the current project: a parent of
            // another project would silently move the page out of it.
            if ($parent->project_id !== $page->project_id) {
                abort(422, 'A move never changes the page project.');
            }

            if (in_array($page->id, $parent->ancestorIds(), true)) {
                abort(422, 'This parent would create a cycle in the hierarchy.');
            }
        }

        $before = ['parent_id' => $page->parent_id];

        $page = DB::transaction(function () use ($page, $parentId, $user, $before): KnowledgePage {
            $page->update(['parent_id' => $parentId]);

            if ($page->parent_id !== $before['parent_id']) {
                RecordActivity::run(AuditEventType::KnowledgePageUpdated, $page, $user, [
                    'parent_id' => ['before' => $before['parent_id'], 'after' => $page->parent_id],
                ]);
            }

            return $page;
        });

        return $page->refresh();
    }

    public function asController(ActionRequest $request, KnowledgePage $page, KnowledgeRedirectResponder $responder): RedirectResponse
    {
        $page = $this->handle($page, $request->user(), $request->validated());

        return $responder->moved($page);
    }
}

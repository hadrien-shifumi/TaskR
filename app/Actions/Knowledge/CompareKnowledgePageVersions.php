<?php

namespace App\Actions\Knowledge;

use App\Models\KnowledgePage;
use App\Models\KnowledgePageVersion;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class CompareKnowledgePageVersions
{
    use AsController;
    use AsObject;

    /**
     * Get two versions of a page for comparison (diff).
     *
     * Returns both versions with their full content so the frontend
     * can display a diff.
     *
     * @return array{from: array{id: string, title: string, body: string, editor: string|null, createdAt: string|null}, to: array{id: string, title: string, body: string, editor: string|null, createdAt: string|null}}
     */
    public function handle(KnowledgePage $page, KnowledgePageVersion $from, KnowledgePageVersion $to, ?User $user = null): array
    {
        // Access check.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessKnowledgePageAccessible($user, $page);
        }

        // Both versions must belong to this page.
        abort_unless($from->page_id === $page->id, 404, 'Version not found for this page.');
        abort_unless($to->page_id === $page->id, 404, 'Version not found for this page.');

        return [
            'from' => [
                'id' => $from->id,
                'title' => $from->title,
                'body' => $from->body,
                'editor' => $from->editor?->name,
                'createdAt' => $from->created_at?->toISOString(),
            ],
            'to' => [
                'id' => $to->id,
                'title' => $to->title,
                'body' => $to->body,
                'editor' => $to->editor?->name,
                'createdAt' => $to->created_at?->toISOString(),
            ],
        ];
    }

    /**
     * JSON endpoint for the version-compare dialog: both full bodies ride
     * along so the client-side diff renders without a second round trip.
     */
    public function asController(Request $request, KnowledgePage $page, KnowledgePageVersion $from, KnowledgePageVersion $to): JsonResponse
    {
        $data = $this->handle($page, $from, $to, $request->user());

        return response()->json($data);
    }
}

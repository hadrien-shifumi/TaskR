<?php

namespace App\Actions\Knowledge;

use App\Models\KnowledgePage;
use App\Models\User;
use App\Presenters\Knowledge\KnowledgePresenter;
use App\Support\WorkspaceContext;
use Lorisleiva\Actions\Concerns\AsObject;

class GetKnowledgePage
{
    use AsObject;

    /**
     * @return array<string, mixed>
     */
    public function handle(KnowledgePage $page, ?User $user = null): array
    {
        // Out-of-scope pages fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessKnowledgePageAccessible($user, $page);
        }

        $page->loadMissing('lastEditor');

        return KnowledgePresenter::detail($page);
    }
}
